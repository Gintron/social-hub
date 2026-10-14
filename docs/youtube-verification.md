# YouTube: verifikacija Google aplikacije

Ovo je pripremni dokument za verifikaciju OAuth aplikacije „Social Media Hub“ u projektu
`social-media-hub-511516`, za osjetljive opsege `youtube.upload` i `youtube.readonly`. Sadrži adrese
koje Google traži, tekstove obrazloženja za svaki opseg i skriptu za demo video, koji se snima samo ako
ga Google zatraži.

## Adrese koje Google čita

| Polje u konzoli | Adresa |
|---|---|
| Početna stranica aplikacije | `https://hub.uselisto.com/aplikacija` (javna, bez prijave) |
| Pravila privatnosti | `https://hub.uselisto.com/privatnost` (sadrži izjavu o Google podacima) |
| Uvjeti korištenja | `https://hub.uselisto.com/uvjeti` |
| Ovlaštena domena | `uselisto.com` (DNS ima Googleov `google-site-verification` zapis) |
| OAuth klijent, redirect URI | `https://hub.uselisto.com/youtube/callback` |

## Obrazloženje opsega (engleski, za obrazac)

**`https://www.googleapis.com/auth/youtube.upload`**

> Social Media Hub is an internal publishing tool used only by the owner's brands. After a team member
> approves a short vertical video (YouTube Shorts) in the tool, the app uploads that video to the brand's own
> YouTube channel (videos.insert), with the title, description and privacy setting the team member chose. The
> app uploads only the videos approved for that channel. It does not upload to any other channel and does not
> read other content.

**`https://www.googleapis.com/auth/youtube.readonly`**

> The app reads the identity of the connected channel (channels.list with mine=true), so the team can confirm
> which channel is connected. It reads the view, like and comment counts of the videos it uploaded
> (videos.list with part=statistics) to show post performance in the tool. It does not read comments, messages,
> subscriber lists or other videos.

## Skripta za demo video (2–3 min, engleska naracija)

Priprema: screen recording cijelog ekrana, 1080p. Demo objavu postavi na `unlisted` ili `private`, nikad
`public`. Koristi kanal koji je test ili kanal brenda. Ne prikazuj `.env`, tajne klijenta ni tokene.

1. **0:00–0:15, početna stranica.** Otvori `https://hub.uselisto.com/aplikacija`.
   *Narration:* "This is Social Media Hub, an internal publishing tool for the owner's brands. A team member
   approves every post before it is published."
2. **0:15–0:40, prijava i povezivanje kanala.** Prijavi se u administraciju. Otvori Društveni računi, klikni
   **Poveži YouTube** i odaberi brend.
   *Narration:* "The team connects a YouTube channel through Google OAuth."
3. **0:40–1:05, Google zaslon za pristanak.** Pokaži naziv aplikacije, oba opsega („Manage your YouTube videos“,
   „View your YouTube account“) i klikni Allow. Ako Google prikaže upozorenje „unverified app“, pokaži ga.
   *Narration:* "The consent screen asks for exactly two scopes: to upload videos, and to read the channel and
   the statistics of its own videos."
4. **1:05–1:20, provjera kanala.** Vrati se u hub. Pokaži povezani kanal i klikni **Provjeri**.
   *Narration:* "The app reads the connected channel to confirm the connection."
5. **1:20–1:50, nacrt.** Otvori nacrt i karticu YouTube. Pokaži format **Short**, tekst objave (prvi red je
   naslov, ostatak opis s poveznicom) i polje **Vidljivost na YouTubeu**.
   *Narration:* "The team reviews the title, the description and the visibility before it approves the draft."
6. **1:50–2:20, odobrenje i upload.** Odobri nacrt, pričekaj objavu i u YouTube Studiju pokaži video na kanalu.
   *Narration:* "After approval, the app uploads the video to the brand's channel."
7. **2:20–2:45, učinak.** Otvori stranicu za učinak objava i pokaži broj pregleda, lajkova i komentara.
   *Narration:* "The app reads the view, like and comment counts of its own uploads. It does not read comments
   or subscriber data."
8. **2:45–3:00, pravila.** Otvori `https://hub.uselisto.com/privatnost`.
   *Narration:* "The privacy policy is at hub.uselisto.com slash privatnost."

## Što se još mora napraviti

- Google za osjetljive opsege traži demo video. Snimanje je potrebno samo ako ga Verification Center zatraži
  pri slanju.
- Odvojeno od verifikacije OAuth aplikacije je **YouTube API Services audit** (obrazac *Audit and Quota
  Extension*). Bez njega uploadi iz projekta ostaju privatni, bez obzira na verifikaciju.
