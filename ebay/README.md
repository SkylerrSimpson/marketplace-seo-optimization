# eBay — Product Metadata Pipeline (planned)

Not implemented yet. Mirror the Shopify implementation (see `../shopify/README.md`):
read-only audit/export -> author/assemble -> reviewable output -> guarded write.

- API: Sell / Taxonomy API — https://developer.ebay.com/develop/api/sell/taxonomy_api
- Add credentials to `.env` (and `.env.example` placeholders) under the eBay section.
- Scripts go in `ebay/scripts/`, data in `ebay/data/{input,drafts,output}`.
- Reuse `lib/bootstrap.php`; add eBay path constants there when work begins.
