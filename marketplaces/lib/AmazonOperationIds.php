<?php

declare(strict_types=1);

class AmazonOperationIds
{
    const GET_FEED             = 'getFeed';
    const GET_FEEDS            = 'getFeeds';
    const CREATE_FEED          = 'createFeed';
    const CANCEL_FEED          = 'cancelFeed';
    const CREATE_FEED_DOCUMENT = 'createFeedDocument';
    const GET_FEED_DOCUMENT    = 'getFeedDocument';

    const GET_LISTINGS_ITEM    = 'getListingsItem';
    const PUT_LISTINGS_ITEM    = 'putListingsItem';
    const PATCH_LISTINGS_ITEM  = 'patchListingsItem';
    const DELETE_LISTINGS_ITEM = 'deleteListingsItem';

    const GET_MARKETPLACE_PARTICIPATIONS   = 'getMarketplaceParticipations';
    const GET_DEFINITIONS_PRODUCT_TYPE     = 'getDefinitionsProductType';
    const SEARCH_DEFINITIONS_PRODUCT_TYPES = 'searchDefinitionsProductTypes';

    const GET_CATALOG_ITEM        = 'getCatalogItem';
    const SEARCH_CATALOG_ITEMS    = 'searchCatalogItems';
    const SEARCH_LISTINGS_ITEMS   = 'searchListingsItems';

    const CREATE_REPORT       = 'createReport';
    const GET_REPORT          = 'getReport';
    const GET_REPORT_DOCUMENT = 'getReportDocument';
}
