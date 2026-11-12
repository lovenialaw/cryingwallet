# crying wallet

A phone-first spending app for Bank Islam statements.

GitHub only stores **the app**. Your PDFs, rows, types, and Gemini key stay **on the phone** that opened the site (browser storage for this app). They are not uploaded when you tap Read statements.

## Use it on your phone

1. In the GitHub repo: **Settings → Pages → Build and deployment → Source: GitHub Actions**.
2. After the **Phone app** workflow finishes, open  
   `https://lovenialaw.github.io/cryingwallet/`
   on your phone (Safari on iPhone).
3. Share → **Add to Home Screen**. Open it from the home screen after that.
4. On **Add**, pick your statement PDFs. Parsing happens on the phone.
5. Optional: on this PC, Types is still XAMPP. In **More**, tap **Download a backup for your phone**, then on the phone **More → Restore a backup**.

The Gemini key, if you paste one, is saved on that phone only. Do not commit `data/gemini.key`.

## Keep it safe

- Clearing Safari/Chrome data for this site **wipes** the wallet on that phone. Download a JSON backup from **More** and keep it in Files.
- Each phone/browser has its own copy. The PC XAMPP copy and the phone copy do not sync by themselves — use the backup file if you want them to match.
- A public GitHub repo is fine for this app because money data is not in the repo. Make the repo private only if you also want the source hidden (GitHub Pages on a private repo may need a paid plan).

## On this PC (XAMPP)

Open `http://localhost/cryingwallet/` for the original PHP app, or `http://localhost/cryingwallet/index.html` for the same on-device app the phone uses.
