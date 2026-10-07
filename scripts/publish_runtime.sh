#!/usr/bin/env bash
set -euo pipefail
# Only CI executes this. No force, no secret file copy, no production HTTP calls.
: "${GITHUB_SHA:?}" "${GITHUB_REPOSITORY:?}" "${RUNNER_TEMP:?}"
[[ "$GITHUB_REPOSITORY" == ringotom1980/tpc_data_system ]]
source_dir="$PWD"
artifact_dir="$RUNNER_TEMP/tpc-runtime-artifact"
runtime_dir="$RUNNER_TEMP/tpc-runtime-git"
runtime_branch=hostinger-runtime-tpc
remote_url=https://github.com/ringotom1980/tpc_data_system.git
[[ "$(git rev-parse HEAD)" == "$GITHUB_SHA" ]]
header=$(git config --get http.https://github.com/.extraheader)
[[ -n "$header" ]]
remote_git() { git -c http.https://github.com/.extraheader= -c "http.https://github.com/.extraheader=$header" "$@"; }
current_main() { remote_git ls-remote "$remote_url" refs/heads/main | awk '{print $1}'; }
[[ "$(current_main)" == "$GITHUB_SHA" ]] || { echo 'Refusing stale source'; exit 1; }
python3 scripts/build_runtime.py --source "$source_dir" --output "$artifact_dir" --sha "$GITHUB_SHA" > "$RUNNER_TEMP/tpc-runtime-manifest.json"
mkdir "$runtime_dir"
git -C "$runtime_dir" init -q
git -C "$runtime_dir" remote add origin "$remote_url"
existing=$(remote_git ls-remote "$remote_url" "refs/heads/$runtime_branch" | awk '{print $1}')
if [[ -n "$existing" ]]; then
    remote_git -C "$runtime_dir" fetch --no-tags origin "refs/heads/$runtime_branch"
    git -C "$runtime_dir" checkout -q -b "$runtime_branch" FETCH_HEAD
    published=$(git -C "$runtime_dir" show HEAD:SOURCE_COMMIT)
    [[ "$published" =~ ^[0-9a-f]{40}$ ]]
    git merge-base --is-ancestor "$published" "$GITHUB_SHA" || { echo 'Refusing source rollback or unknown published source'; exit 1; }
else
    git -C "$runtime_dir" checkout -q --orphan "$runtime_branch"
fi
rsync -a --delete --exclude='.git/' "$artifact_dir/" "$runtime_dir/"
git -C "$runtime_dir" config user.name 'TPC runtime publisher'
git -C "$runtime_dir" config user.email '41898282+github-actions[bot]@users.noreply.github.com'
git -C "$runtime_dir" add --all
if git -C "$runtime_dir" diff --cached --quiet && [[ -n "$existing" ]]; then
    echo 'Runtime already current'; exit 0
fi
git -C "$runtime_dir" commit -q -m "Runtime from $GITHUB_SHA"
[[ "$(current_main)" == "$GITHUB_SHA" ]] || { echo 'Refusing stale source before publish'; exit 1; }
# Normal push refuses concurrent branch creation/update; retry must fetch its new parent.
remote_git -C "$runtime_dir" push origin "HEAD:refs/heads/$runtime_branch"
