> This page is synced from `docs/wiki` in the repository. Edits made here are overwritten on the next sync; suggest changes through a pull request or in [Discussions (Docs and guides)](https://github.com/APESCIC/MyAPES.Account/discussions/categories/docs-and-guides).

# Account settings

**Audience:** everyone signed in (public accounts, staff, volunteers and students)  
**Purpose:** where login details, personal information, security controls and privacy choices live after v0.77.0.

## Open account settings

1. Open your **avatar** in the top app bar and choose **Account**, or press **Ctrl K** (⌘K on Mac) and choose **Your account**.
2. Use the tabs at the top to move between **Account**, **Profile**, **Security** and **Privacy**.

Old `/profile` links and bookmarks redirect to the matching tab (for example `#passkeys` opens **Security**).

## The four pages

| Tab | Path | What you manage |
| --- | --- | --- |
| **Account** | `/account` | Sign-in email, username (public accounts), sign-in methods summary, **Appearance** (skin, light or dark, calm mode). |
| **Profile** | `/account/profile` | Personal and contact details, photo. Staff on directory sign-in add workplace details colleagues need; directory name and email stay read-only. |
| **Security** | `/account/security` | Password, passkeys, authenticator app, telephone verification PIN, signed-in devices, recent security activity. |
| **Privacy** | `/account/privacy` | Contact preferences, optional email, push and email notification types, cookies summary, download my data, deletion requests. |

Push notifications, quiet hours and the iPhone Home Screen guide live under **Privacy → Notifications**. See [[Push notifications|Push-notifications]].

Appearance is also available from the **avatar** menu (Theme at the bottom) without opening **Account** first. See [[User guides|User-guides#appearance]].

## Screenshots and design history

Repository captures under `docs/design-qa/ui-review-profile-*.png` and `save-toast-profile-*.png` show the **previous single profile page**. After v0.77.0 the live UI uses the four tabs above; new screenshots should be taken from `/account`, `/account/profile`, `/account/security` and `/account/privacy` when design QA is refreshed.

## Related guides

- In-app **Help** → **Account settings** (public guide)
- [[Staff, volunteers and students guide|Guide-Staff-volunteers-and-students]] → Your account
- [Privacy notice](https://myaccount.myapes.me.uk/privacy) for how data is used