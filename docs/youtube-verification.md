# YouTube: verifikacija Google aplikacije

Dokument prati verifikaciju OAuth aplikacije „Social Media Hub“ u projektu `social-media-hub-511516`, za
osjetljive opsege `youtube.upload` i `youtube.readonly`: što je u konzoli, tekst obrazloženja koji je upisan
u obrazac, i skriptu za demo video koji Google traži.

## Stanje (14. 10. 2026.)

- Početna stranica `https://hub.uselisto.com/aplikacija`: javna, bez prijave; objavljena s ovim dokumentom.
- Pravila privatnosti `https://hub.uselisto.com/privatnost` i uvjeti `https://hub.uselisto.com/uvjeti`: javni.
- Ovlaštena domena `uselisto.com`. Branding je verificiran i prikazuje se korisnicima.
- Aplikacija je objavljena (**In production**). Test korisnik: marijan10vk@gmail.com.
- Opsezi `youtube.upload` i `youtube.readonly` dodani su u Data Access (osjetljivi).
- Obrazloženje je napisano (tekst ispod), ali se ne može spremiti dok nema poveznice na video.
- **Nedostaje demo video.** Google ga traži za osjetljive opsege. Poveznica na neizlistan YouTube video
  upisuje se u polje „YouTube link“ u odjeljku Demo video, zatim se šalje (Confirm).
- Odvojeno od verifikacije: **YouTube API Services audit** (obrazac *Audit and Quota Extension*). Dok audit
  nije odobren, uploadi iz projekta ostaju privatni, bez obzira na verifikaciju OAuth aplikacije.

## Adrese koje Google čita

| Polje u konzoli | Adresa |
|---|---|
| Početna stranica aplikacije | `https://hub.uselisto.com/aplikacija` |
| Pravila privatnosti | `https://hub.uselisto.com/privatnost` (sadrži izjavu o Google podacima) |
| Uvjeti korištenja | `https://hub.uselisto.com/uvjeti` |
| Ovlaštena domena | `uselisto.com` |
| OAuth klijent, redirect URI | `https://hub.uselisto.com/youtube/callback` |

## Obrazloženje opsega (upisano u obrazac, jedan tekst za oba opsega, do 1000 znakova)

> Social Media Hub is an internal publishing tool used only by the owner's brands. youtube.upload: after a team
> member approves a short vertical video (YouTube Shorts) in the tool, the app uploads it to that brand's own
> YouTube channel (videos.insert), with the title, description and privacy setting the team chose. It uploads
> only videos approved for that channel. youtube.readonly: the app reads the identity of the connected channel
> (channels.list with mine=true) so the team can confirm which channel is connected, and reads the view, like and
> comment counts of its own uploaded videos (videos.list with part=statistics) to show post performance. It does
> not read comments, messages, subscriber lists or other videos.

## Skripta za demo video (2–3 min, engleska naracija)

Priprema: screen recording cijelog ekrana, 1080p. Demo objavu postavi na `unlisted` ili `private`, nikad
`public`. Koristi kanal koji je test ili kanal brenda. Ne prikazuj `.env`, tajne klijenta ni tokene.

Google traži da se u videu vidi ekran „unverified app“ (aplikacija još nije verificirana) i da video obuhvati
sve OAuth klijente koji su dodijeljeni projektu. U projektu je jedan klijent, „Youtube client“.

1. **0:00–0:15, početna stranica.** Otvori `https://hub.uselisto.com/aplikacija`.
   *Narration:* "This is Social Media Hub, an internal publishing tool for the owner's brands. A team member
   approves every post before it is published."
2. **0:15–0:40, prijava i povezivanje kanala.** Prijavi se u administraciju. Otvori Društveni računi, klikni
   **Poveži YouTube** i odaberi brend.
   *Narration:* "The team connects a YouTube channel through Google OAuth."
3. **0:40–1:05, Google zaslon za pristanak.** Pokaži upozorenje „Google hasn't verified this app“ i nastavi dalje.
   Pokaži naziv aplikacije i oba opsega („Manage your YouTube videos“, „View your YouTube account“) i klikni Allow.
   *Narration:* "The consent screen asks for exactly two scopes: to upload videos, and to read the channel and the
   statistics of its own videos."
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

Poveznicu na video (neizlistan YouTube) upiši u polje „YouTube link“ u Verification Centeru pa pošalji zahtjev.
