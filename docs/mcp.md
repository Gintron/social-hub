# MCP server: hub kroz AI agenta

Hub izlaže svoje radnje kao MCP alate, pa Claude Code (ili zakazana Claude rutina) može pregledati
kandidate, napisati objavu i ostaviti je na odobrenje — kroz **iste akcije** koje koristi i sučelje,
ne kroz drugi put u bazu.

Server: `App\Mcp\Servers\SocialHubServer`, rute u [`routes/ai.php`](../routes/ai.php).

## Alati

| Alat | Ovlast | Što radi |
|---|---|---|
| `hub.list_sources` | `mcp` | Brendovi, njihovi izvori i povezani kanali. Odavde počinje svaki razgovor. |
| `hub.list_candidates` | `mcp` | Sadržaj koji bi mogao postati objava (zadano: živ i još bez objave). |
| `hub.get_candidate` | `mcp` | Cijela stavka: tekst, činjenice, oznake, cijena, slike. |
| `hub.list_drafts` | `mcp` | Objave i njihovo stanje po kanalu. |
| `hub.publish_stats` | `mcp` | Što je izašlo, što je palo, što čeka ručnu objavu. |
| `hub.create_draft` | `mcp:draft` | Od kandidata radi objavu koja čeka odobrenje. |
| `hub.update_variant` | `mcp:draft` | Mijenja tekst jednog kanala ili ga isključuje iz objave. |
| `hub.render_preview` | `mcp:draft` | Ponovno renderira sliku, po želji drugim predloškom. |
| `hub.approve_draft` | `mcp:approve` | Odobrava i po želji zakazuje. |
| `hub.publish_draft` | `mcp:publish` | Objavljuje odmah. |
| `hub.mark_manual_posted` | `mcp:publish` | Bilježi da je čovjek zalijepio objavu u Facebook grupu. |

## Tokeni su opsegom ograničeni

Agent koji piše nacrte ne treba moći objaviti. Token nosi točno onoliko koliko mu treba:

```bash
php artisan hub:issue-mcp-token ti@example.com --scope=draft
```

| `--scope` | Ovlasti |
|---|---|
| `read` | `mcp` |
| `draft` | `mcp`, `mcp:draft` |
| `approve` | + `mcp:approve` |
| `publish` | + `mcp:publish` |

Alat koji token ne smije pozvati vraća grešku s imenom ovlasti koja nedostaje, umjesto da odradi pola
posla. E-mail mora biti u `HUB_ADMIN_EMAILS` — token djeluje kao ta osoba i tako piše u dnevnik.

## Spajanje

**Lokalno (stdio), za rad na vlastitom stroju:**

```json
{
  "mcpServers": {
    "social-hub": {
      "command": "php",
      "args": ["artisan", "mcp:start", "social-hub"],
      "cwd": "/putanja/do/social-hub"
    }
  }
}
```

Lokalni transport nema token: tko može pokrenuti `artisan`, ionako je na stroju.

**Udaljeno (HTTP), za agente i rutine:**

```json
{
  "mcpServers": {
    "social-hub": {
      "type": "http",
      "url": "https://hub.example.com/mcp",
      "headers": { "Authorization": "Bearer 1|..." }
    }
  }
}
```

Provjera bez klijenta:

```bash
curl -s -X POST https://hub.example.com/mcp \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

Bez tokena ruta vraća `401` i `WWW-Authenticate: Bearer`.

## Što agent smije sam

Upute koje server šalje klijentu (`#[Instructions]`) kažu isto što i ovaj odjeljak, pa ih agent vidi
prije prvog poziva:

- **Čitanje i pisanje nacrta je sigurno.** `hub.create_draft` ostavlja objavu čovjeku na pregled.
- **`hub.approve_draft` i `hub.publish_draft` čine objavu javnom.** Agent ih ne poziva sam od sebe,
  nego kad je zatraženo baš za tu objavu.
- **`hub.mark_manual_posted` ne objavljuje ništa** — bilježi da je netko ručno zalijepio objavu u
  grupu. Poziva se nakon što čovjek to potvrdi, ne da bi se zadatak „zatvorio".

Hub uz to provjerava sadržaj: tekst s iznosom ili poveznicom koje nema u podacima stavke se odbija
(vidi `App\Ai\CaptionValidator`), pa ni pogrešno vođen agent ne može objaviti izmišljenu cijenu.

## Jutarnja rutina

Primjer zadatka za zakazanu rutinu s `draft` tokenom:

> Za brend `studentski-poslovi`: pozovi `hub.list_candidates`, uzmi do 5 novih oglasa, za svaki
> pročitaj `hub.get_candidate` i napiši objavu za Facebook i Instagram koristeći isključivo podatke
> iz stavke. Napravi nacrte s `hub.create_draft`. Ništa ne odobravaj i ne objavljuj — javi mi popis
> naslova koje si pripremio.

Isto radi i bez agenta, ugrađeno: `php artisan hub:agent-draft studentski-poslovi` (vidi
[README](../README.md#ai-agent)).
