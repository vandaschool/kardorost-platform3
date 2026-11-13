# Kardorost Next

A fresh Laravel 13 / PostgreSQL / Vue 3 / Inertia 3 implementation of Kardorost.

Implemented modules include secure accounts and scoped panels, course enrollment and capacity, learning rooms and private submissions, organization reports, installment billing, transfer receipts, consultation scheduling and gateway verification, a private manual refund workflow, and organization seat quotas with atomic group enrollment and scoped CSV exports. Sponsored seats share cohort capacity with personal reservations and do not create personal tuition invoices. Internal contract invoices now support immutable issuance, full or partial gateway and independently reviewed transfer payments with a cumulative allocation ledger, and scoped organization access. Original invoice totals stay fixed; late or concurrent funds exceeding the remaining balance are retained in review without allocation. Organization refunds, excess-fund resolution, dated organization installment schedules, formal tax/accounting integration and automatic license purchases remain incomplete.

The assessment infrastructure supports immutable publications, resumable encrypted answers and versioned reports for five-option subscale sums with reversed items. Real instruments and scoring keys will be selected later; no questionnaire content is seeded into the application. Other instrument types, paid assessments, career reports and AI interpretation remain incomplete.

See [the Persian installation and delivery guide](README-FA.md) for setup, completed functionality, remaining modules, verification results, and integration limitations. This repository contains incremental working modules, not the completed platform.
