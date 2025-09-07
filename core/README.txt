SDE Accounting — Integrated Build (2025-08-11)

This is a full plugin you can install directly and remove the old one.

Includes:
- CC Report (transactions list + summary; period: This year / Always)
- Cost Center Registration & Insurance fields (incl. mileage)
- Maintenance Log CPT + Service Tags + CC screen panel
- Admin notice for upcoming expiries (30 days)

Install:
1) Delete the old plugin from Plugins.
2) Upload this ZIP via Plugins → Add New → Upload.
3) Activate “SDE Modular (Milestoned)” (this integrated build keeps the same main plugin header).


---
2025-08-30 Fixes:
- Rewrote UI router to remove placeholder text and added **Transactions** submenu (admin.php?page=sde-modular-trans).
- Verified CC Report module; ensured `defined('ABSPATH')` guard and menu registration.
- Packaged as ACCOUNTING-STREETS.OFTIMOR-INTEGRATED-fixed.zip for install.

2025-08-30 Amendments:
- Accounts: Added **Add New Account** flow (submenu + page) and a button on Accounts overview.
- Transactions (Roadmap #1): Added compact **column width** definitions via <colgroup>, preserved admin-only edit/delete, kept redirect-after-save. Added **Cost Center filter** on list.
- Accounts (Roadmap #2): Account detail already had date/YTD/QTD/MTD filters; added **CSV export** for the ledger view.
- Cost Centers (Roadmap #3): Re-introduced **CC filter** into Transactions; CC column stays visible on list and forms.
