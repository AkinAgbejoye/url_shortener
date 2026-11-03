# Authorized site import previews

Authorized imports create private, editable, read-only previews for owners who currently control a domain and separately attest that they hold reproduction rights. They are not a cloning or hosting feature: `website_intelligence.imports.publishing_enabled` is hard-disabled, there is no publish route, and imported content is never served as a public site.

## Authorization and lifecycle

An import requires a verified account, an unexpired domain verification, a 40–1,000 character reproduction-rights attestation, and at least one declared licence. The encrypted attestation and licence declarations expire no later than the domain verification. The worker rechecks domain control, rights, account verification, account suspension, moderation blocks, and cancellation before acquisition and before every asset.

Owners can cancel queued work, retry a recoverable failure while authorization is current, explicitly confirm a sanitized preview, request takedown, or delete retained content. If domain control or rights expire, access stops. Re-authorization requires a fresh current verification for the same domain plus a new rights attestation. Takedown and deletion immediately remove preview content and encrypted assets while retaining bounded provenance and the lifecycle state for audit. The retention job later removes expired metadata.

## Acquisition and sanitation boundary

The importer uses the verified HTTPS origin only. The page cap is fixed at one HTML document; defaults allow 20 assets, 1 MB for the page, 2 MB per asset, 10 MB total, and 60 seconds. Environment settings beginning with `INTELLIGENCE_IMPORT_` tune the remaining bounded limits. Archives, executables, scripts, SVG, traversal paths, ambiguous types, polyglots, signature mismatches, oversized files, EICAR fixtures, and content-confusion payloads are rejected. No target-site credentials are accepted or stored.

HTML sanitation removes scripts, forms, authentication/payment inputs, frames, embeds, media execution surfaces, metadata redirects, hidden elements, event handlers, remote link behavior, trackers, unsafe style constructs, and remote URLs. Same-origin PNG, JPEG, GIF, WebP, and CSS assets must pass extension, declared-type, detected-type, signature, path, byte, and malware checks. CSS imports, URLs, expressions, binding behavior, and fixed overlays are removed. Approved links become inert and approved images become bounded data URLs. Only sanitized HTML, a small editable heading/title representation, hashes, scan outcomes, verification evidence, import time, and declared-licence counts are retained.

Rejected assets are isolated from accepted assets and produce a `partially_ready` preview with bounded counts. Root sanitizer, authorization, time, or total-budget failures enter a recoverable failed state without persisting raw HTML. Operations snapshots expose bounded import state and rejected-asset counters without domains, URLs, attestations, or content.

## Preview isolation

The private preview route is owner scoped and requires current rights and domain authorization on every request. The imported document is placed in an iframe with an empty `sandbox` token set, giving it an opaque origin with no scripts, forms, popups, navigation, storage, or application-origin access. The outer response sets `default-src 'none'`, allows only inline shell styles and data images, denies framing, disables referrers, prevents MIME sniffing, and uses `private, no-store` caching.

Operators should treat takedown requests as immediate and should investigate sustained sanitizer failures or rejected-asset spikes without logging source content or rights attestations.
