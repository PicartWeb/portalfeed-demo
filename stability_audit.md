# Stability + Schema Alignment Audit

## Audited tables referenced by the PHP project

- `users`
- `user_bans`
- `posts`
- `post_media`
- `comments`
- `post_likes`
- `follows`
- `conversations`
- `messages`
- `notifications`
- `groups`
- `group_members`
- `reports`
- `audit_log`
- `gigs`

## Mismatches found

1. `settings.php` rendered profile fields (`skills`, `country`, `timezone`, `link_tiktok`, `link_instagram`, `link_x`, `link_portfolio`) without selecting them from `users`, which caused undefined-index notices.
2. `settings.php` submitted two separate `bio` fields with the same name, making profile updates ambiguous.
3. `dashboard.php` and `dash1.php` expected a physical `groups.members_count` column, while the rest of the project derives membership from `group_members`.
4. `post_pin.php` pinned posts globally and required `owner/admin`, but `group_view.php` exposed pin controls to group moderators too. Group pins also incorrectly cleared pins across unrelated feed/group scopes.
5. `comment_add.php` and `post_like_toggle.php` allowed direct interaction with group posts without verifying that the current user belongs to that group.
6. `post_create.php` accepted `group_id` blindly, so a crafted request could create posts in groups the user had not joined.
7. `post_delete.php`, `post_update.php`, `comment_delete.php`, `comment_add.php`, and `post_like_toggle.php` always redirected to `dashboard.php`, breaking group flows and making group actions feel inconsistent.
8. `follow_toggle.php` updated follows but never created the corresponding `follow` notification even though the notification system already supports that type.
9. `groups.php` generated slugs without checking uniqueness, which would collide once the schema enforces a unique `slug`.
10. `notifications_mark_read.php` and `report_create.php` trusted raw redirect targets from POST input.
11. `db.php` hardcoded credentials only and discarded the real PDO error, making deployment/configuration harder and failures harder to trace.

## Fixes applied

- Expanded the `settings.php` user query to match the rendered profile fields and removed the duplicate bio field.
- Replaced dashboard-side `groups.members_count` assumptions with live `group_members` counts.
- Made post/comment/like/pin flows group-aware so actions return to the correct page and enforce group membership where required.
- Aligned group pin permissions with the UI by allowing group owner/moderator pinning inside groups while keeping global feed pinning restricted to portal staff.
- Added follow notifications on successful follow actions.
- Added group-membership validation before creating a group post.
- Added unique slug generation for newly created groups.
- Sanitized local redirects in report and notification actions.
- Made `db.php` configurable via environment variables and log connection failures server-side.

## Remaining intentional notes

- `dashboard.php` and `dash1.php` reference `gigs`; the schema includes a minimal `gigs` table so those sections do not fail, but the project does not yet include full gig-management flows.
- `db.php` still defaults to the existing database name `new` to avoid silently breaking your current local setup.
