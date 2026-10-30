# docs/wiki

These files are the source of the GitHub wiki: https://github.com/APESCIC/MyAPES.Account/wiki

- Edit pages here, through a pull request. Edits made in the wiki itself are overwritten.
- `.github/workflows/wiki-sync.yml` copies this folder to the wiki on every push to `main` that changes `docs/wiki/**` (or when run by hand). This README is not copied.
- File name = page name: `Guide-Admins.md` becomes the page "Guide Admins". Prefixes: `Guide-`, `Module-`, `Plugin-`, `Dev-`.
- `_Sidebar.md` and `_Footer.md` appear on every page.
- Each page starts with the sync note, then: title, audience, purpose, content, related links.
- Link to `docs/` and the README rather than copying them; things that change with code live in `docs/`.
- Public user guides live in the app's help pages (`/help`), not here.
- Screenshots: test accounts and fake data only; no real names, emails, pets, cases or medical details. Put them in `docs/wiki/images/`.
- Never include secrets, keys, tokens or personal data. UK English, plain wording.
