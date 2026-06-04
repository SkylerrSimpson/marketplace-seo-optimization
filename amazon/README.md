# Amazon — Product Metadata Pipeline (planned)

Not implemented yet. Mirror the Shopify implementation (see `../shopify/README.md`):
read-only audit/export -> author/assemble -> reviewable output -> guarded write.

- API: SP-API Listings Items — https://developer-docs.amazon.com/sp-api/reference/listings-items-v2020-09-01
- Add credentials to `.env` (and `.env.example` placeholders) under the Amazon section.
- Scripts go in `amazon/scripts/`, data in `amazon/data/{input,drafts,output}`.
- Reuse `lib/bootstrap.php`; add Amazon path constants there when work begins.
