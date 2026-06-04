# Walmart — Product Metadata Pipeline (planned)

Not implemented yet. Mirror the Shopify implementation (see `../shopify/README.md`):
read-only audit/export -> author/assemble -> reviewable output -> guarded write.

- API: Marketplace API — https://developer.walmart.com/us-marketplace/lang-es/docs/utilities-overview
- Add credentials to `.env` (and `.env.example` placeholders) under the Walmart section.
- Scripts go in `walmart/scripts/`, data in `walmart/data/{input,drafts,output}`.
- Reuse `lib/bootstrap.php`; add Walmart path constants there when work begins.
