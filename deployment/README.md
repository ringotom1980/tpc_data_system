# TPC Git runtime migration approval scope

Only ringotom1980/tpc_data_system, Hostinger tpc.jinghong.pw / u327657097.
This is a local proposal until approved. Never deploy main into the original app.

## Changes

Seven PHP adapters preserve canonical /tpc_data_system/Public URLs and require the
original config/db_connection.php, Public/vendor and Public/TCPDF through the
sibling original app. Do not read, move, copy or replace the secrets/libraries.
Runtime publisher is main-only with job-scoped contents:write; it produces
hostinger-runtime-tpc using an explicit 31-file allowlist plus SOURCE_COMMIT.
First branch commit is orphan; later commits parent the fetched branch HEAD.
Normal push only, serialized job, reject stale source before build and push,
reject rollback against the last published source. A final main update between
the last check and push is not cross-ref atomic: the next run must deliver it.
GHA execution and the real Hostinger chain still need authorized verification.

FTP deploy.yml becomes workflow_dispatch only. Preserve FTP secrets and rollback
workflow. Manual FTP invocation is not routine validation and needs authorization.

## Approved execution order

1. Recheck main SHA and absence of unexpected changes; ensure FTP has no queued or
   in-progress executions. Prepare reviewed commit for main, but do not push yet.
2. User backs up exact public_html/.htaccess (original 119 bytes, CRLF) and pastes
   root.htaccess.staged into that root file. It protects old/new dot paths,
   config/vendor/TCPDF, SQL/log/backups, old non-Public paths and direct runtime
   access. Options -Indexes changes directory listing permission. SOURCE_COMMIT
   is the only direct runtime file allowed; .well-known remains available.
   TPC_RUNTIME_ENABLED:0 preserves old Public routing. This permission change
   must be specifically included in approval. Do not HTTP GET actual secrets;
   live protection review uses metadata/rule inspection, tests use synthetic data.
3. Push the approved main commit; new CI publishes the runtime branch. Configure
   Hostinger Git for that branch into exactly public_html/tpc-runtime-v1, sibling
   to tpc_data_system. Enable its automatic deployment. Inspect deployed metadata
   and SOURCE_COMMIT, exclusions and preserved app. Do not deploy to public_html
   itself or to tpc_data_system; Hostinger clears untracked target files.
4. User pastes root.htaccess.active, identical except flag 0 becomes 1, into the
   same root file. No canary or extra test page. Verify login GET, safe rendering,
   canonical assets/API unauthenticated redirects/downloads and source marker.
   Direct runtime app URLs must be denied. Unknown Public paths cannot run old PHP.
5. A second approved comment-only main push must deliver the new SOURCE_COMMIT on
   the site, proving main -> CI -> runtime -> automatic Hostinger deployment.

## Excluded acceptance actions

Never run upload confirm on production for acceptance: existing code deletes old
records, renumbers IDs and alters AUTO_INCREMENT. The migration does not authorize
these actions as a normal user operation. Memory XLSX/PDF tests use isolated test
libraries, no DB, no live requests. Actual installed versions/permissions unknown.
The user may separately choose a known read-only PDF output for acceptance; its
font generation may write existing TCPDF font cache. No existing SQL bug is fixed.

## Rollback

Paste staged flag 0 to return to original app while retaining web protection.
If the new protection itself causes incompatibility, restore the exact original
119-byte CRLF root backup. Keep original app/config/vendor/TCPDF and FTP secrets.
Do not force-rewind runtime or delete the original app. A return to the original
unprotected rules needs a deliberate decision, not an automatic API operation.
