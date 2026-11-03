# Extending the engine

All extension points are WordPress filters; register them from a small companion plugin.

## Add a metrics / backlink provider

Implement `PPC\LinkGrowth\Integrations\Contracts\MetricsProvider` (and optionally `BacklinkSource`) and append an instance:

```php
add_filter( 'ppclg_metrics_providers', function ( array $list ) {
    $list[] = new My_Provider( /* … */ );
    return $list;
} );
```

`domainMetrics()` must return normalised keys (`authority`, `authority_label`, `organic_traffic`, `organic_keywords`, `referring_domains`, `backlinks`, `spam_score`) or `null`. Never return estimates you did not get from the provider.

## Add a search provider

Implement `PPC\LinkGrowth\Integrations\Contracts\SearchProvider` and append it through `ppclg_search_providers`. Track your own quota in `remainingToday()`.

## Add a submission adapter (official directory API)

Implement `PPC\LinkGrowth\Submission\Adapters\AdapterInterface` and register through `ppclg_submission_adapters`. `supports()` decides when the adapter applies; `submit()` returns `submitted`, `failed` (with `retryable`) or `needs_manual`. The engine still applies every authorisation gate before calling you.

For most REST-style partner APIs no code is needed: use the *Configured API* fields on the prospect (endpoint, method, headers, JSON body template with `{{business.name}}`-style placeholders, success path, listing-URL path, encrypted API key).

## Add a job type

Implement `PPC\LinkGrowth\Automation\Jobs\JobInterface` and map it through `ppclg_job_types` (`type => class`). Enqueue with `ppclg()->get( Pipeline::class )->enqueueStage( 'my_type', [...] )`.

## Tune relevance

`ppclg_taxonomy` lets you add or re-weight keyword groups, negative terms and site-type hints without editing `data/keywords.json`. `ppclg_discovery_queries` and `ppclg_seed_catalog` do the same for queries and seeds; `ppclg_excluded_domains` extends the platform exclusion list.

## Outbound HTTP

`ppclg_http_request_args` receives the `wp_remote_request` arguments and the URL for every outbound request (useful for proxies or test routing).
