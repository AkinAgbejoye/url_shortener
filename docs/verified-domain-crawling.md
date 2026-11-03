# Verified-domain bounded crawling

Only verified account owners can start a site crawl. Domain challenges support DNS TXT, `/.well-known/shortly-verification`, and a `shortly-verification` HTML meta tag. Challenges expire quickly; successful verifications expire and must be rechecked. Revocation stops all queued, running, or paused crawls.

The crawler reuses the SSRF-safe DNS and peer-pinned transport, sends no cookies or credentials, accepts no authenticated target access, and rechecks verification and moderation before every page. It reads and records the RFC 9309 robots policy before content. Pages must remain on the exact verified HTTPS origin and satisfy owner include/exclude prefixes. Page count, depth, bytes, links, query parameters, runtime, active jobs, redirects, and response sizes are bounded.

Calendar patterns, session parameters, cyclic URLs, duplicate URLs, and duplicate canonical pages are stopped or deduplicated. Only encrypted page URLs and bounded provenance hashes/status/byte counts are retained; raw bodies, cookies, visitor identifiers, credentials, and analytics are not stored. Owners can pause, resume, cancel, revoke, and delete crawls. Deletion cascades all retained page evidence. Crawl work runs on the isolated `intelligence` queue and never enters the redirect request path.
