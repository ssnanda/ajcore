#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="ajforms"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASES_DIR="$ROOT_DIR/releases"
PLUGIN_FILE="$ROOT_DIR/ajforms.php"

GITHUB_REPO="ssnanda/ajforms"
TAG_PREFIX="v"

GITHUB_RELEASE="ask"
GIT_COMMIT="ask"
GIT_PUSH="ask"

usage() {
  cat <<'USAGE'
Usage:
  ./bin/build-release.sh

Interactive flow:
  1. Choose version bump
  2. Build releases/<slug>-<version>.zip
  3. Ask whether to commit to git
  4. Ask whether to push to GitHub
  5. Ask whether to create/upload GitHub Release asset

Options are still supported:
  --version X.Y.Z
  --bump patch|minor|major
  --no-bump
  --slug NAME
  --root PATH
  --repo OWNER/REPO
  --github-release
  --no-github-release
  --git-commit
  --no-git-commit
  --push
  --no-push
  --help

Creates:
  releases/<slug>-<version>.zip
  releases/<slug>.zip
USAGE
}

ask_yes_no() {
  local prompt="$1"
  local default="${2:-n}"
  local answer=""

  if [[ "$default" == "y" ]]; then
    read -r -p "$prompt [Y/n]: " answer
    answer="${answer:-y}"
  else
    read -r -p "$prompt [y/N]: " answer
    answer="${answer:-n}"
  fi

  case "$answer" in
    y|Y|yes|YES|Yes) return 0 ;;
    *) return 1 ;;
  esac
}

get_version() {
  awk -F':' 'tolower($1) ~ /version[ \t]*$/ {gsub(/^[ \t]+|[ \t\r]+$/, "", $2); print $2; exit}' "$PLUGIN_FILE"
}

validate_version() {
  local version="$1"
  if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: version must use X.Y.Z format, example 1.0.1" >&2
    exit 1
  fi
}

bump_version() {
  local current="$1"
  local part="$2"
  local major minor patch
  IFS='.' read -r major minor patch <<< "$current"

  case "$part" in
    patch) patch=$((patch + 1)) ;;
    minor) minor=$((minor + 1)); patch=0 ;;
    major) major=$((major + 1)); minor=0; patch=0 ;;
    *) echo "Error: bump must be patch, minor, or major" >&2; exit 1 ;;
  esac

  echo "$major.$minor.$patch"
}

set_version() {
  local version="$1"
  validate_version "$version"

  perl -0pi -e "s/Version:\s*[0-9]+\.[0-9]+\.[0-9]+/Version: $version/" "$PLUGIN_FILE"
  perl -0pi -e "s/define\(\s*'AJFORMS_VERSION'\s*,\s*'[^']+'\s*\);/define( 'AJFORMS_VERSION', '$version' );/" "$PLUGIN_FILE"
}

require_files() {
  [[ -f "$PLUGIN_FILE" ]] || { echo "Error: missing plugin file at $PLUGIN_FILE" >&2; exit 1; }
  command -v zip >/dev/null 2>&1 || { echo "Error: zip command is required" >&2; exit 1; }
  command -v unzip >/dev/null 2>&1 || { echo "Error: unzip command is required" >&2; exit 1; }
  command -v rsync >/dev/null 2>&1 || { echo "Error: rsync command is required" >&2; exit 1; }
}

require_git() {
  command -v git >/dev/null 2>&1 || { echo "Error: git command is required" >&2; exit 1; }
  git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
    echo "Error: $ROOT_DIR is not a git repository" >&2
    exit 1
  }
}

require_gh() {
  command -v gh >/dev/null 2>&1 || { echo "Error: GitHub CLI is required. Install with: brew install gh" >&2; exit 1; }
  gh auth status >/dev/null 2>&1 || { echo "Error: GitHub CLI is not logged in. Run: gh auth login" >&2; exit 1; }
}

choose_version_from_menu() {
  local current="$1"
  local choice=""
  local custom_version=""

  while true; do
    cat >&2 <<MENU
Current version: $current

Choose release type:
  1) patch  ($(bump_version "$current" patch))
  2) minor  ($(bump_version "$current" minor))
  3) major  ($(bump_version "$current" major))
  4) custom version
  5) no bump / package current version
MENU

    read -r -p "Enter choice (1-5, default=1): " choice
    choice="${choice:-1}"

    case "$choice" in
      1|patch|p) echo "$(bump_version "$current" patch)"; return 0 ;;
      2|minor|m) echo "$(bump_version "$current" minor)"; return 0 ;;
      3|major|M) echo "$(bump_version "$current" major)"; return 0 ;;
      4|custom|c)
        read -r -p "Enter version X.Y.Z: " custom_version
        validate_version "$custom_version"
        echo "$custom_version"
        return 0
        ;;
      5|none|no|n|current) echo "$current"; return 0 ;;
      *) echo "Please choose 1, 2, 3, 4, or 5." >&2; echo "" >&2 ;;
    esac
  done
}

build_zip() {
  local tmp_dir package_dir old_pwd
  tmp_dir="$(mktemp -d)"
  package_dir="$tmp_dir/$PLUGIN_SLUG"
  old_pwd="$(pwd)"

  mkdir -p "$package_dir"

  rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.DS_Store' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='releases' \
    --exclude='bin' \
    --exclude='README.md' \
    --exclude='*.zip' \
    --exclude='.vscode' \
    --exclude='.idea' \
    --exclude='__MACOSX' \
    --exclude='*.swp' \
    --exclude='*.swo' \
    --exclude='*~' \
    "$ROOT_DIR/" "$package_dir/"

  cd "$tmp_dir"

  zip -r "$VERSIONED_ZIP" "$PLUGIN_SLUG" \
    -x "*/.DS_Store" \
    -x "*/__MACOSX/*" >/dev/null

  cp "$VERSIONED_ZIP" "$LATEST_ZIP"

  cd "$old_pwd"
  rm -rf "$tmp_dir"
}

verify_zip() {
  local zip_file="$1"
  [[ -f "$zip_file" ]] || { echo "Error: zip was not created: $zip_file" >&2; exit 1; }

  if ! unzip -l "$zip_file" | awk '{print $4}' | grep -q "^$PLUGIN_SLUG/$"; then
    echo "Error: zip root folder is not $PLUGIN_SLUG/" >&2
    unzip -l "$zip_file" | head >&2
    exit 1
  fi

  if ! unzip -l "$zip_file" | awk '{print $4}' | grep -q "^$PLUGIN_SLUG/ajforms.php$"; then
    echo "Error: zip is missing $PLUGIN_SLUG/ajforms.php" >&2
    unzip -l "$zip_file" | head >&2
    exit 1
  fi
}

git_commit_release_files() {
  require_git
  cd "$ROOT_DIR"

  git add -u

  while IFS= read -r file; do
    case "$file" in
      releases/*|*.zip) continue ;;
      *) git add "$file" ;;
    esac
  done < <(git ls-files --others --exclude-standard)

  if git diff --cached --quiet; then
    echo "Git: nothing to commit"
    return 0
  fi

  git commit -m "Release AJ Forms $VERSION"
}

git_create_tag() {
  require_git
  cd "$ROOT_DIR"
  local tag="$TAG_PREFIX$VERSION"

  if git rev-parse "$tag" >/dev/null 2>&1; then
    echo "Git: tag already exists: $tag"
  else
    git tag "$tag"
    echo "Git: created tag $tag"
  fi
}

git_push_release() {
  require_git
  cd "$ROOT_DIR"

  local current_branch
  current_branch="$(git rev-parse --abbrev-ref HEAD)"

  git push origin "$current_branch"
  git push origin "$TAG_PREFIX$VERSION"
}

publish_github_release() {
  require_gh

  local tag="$TAG_PREFIX$VERSION"
  local title="AJ Forms $VERSION"

  if gh release view "$tag" --repo "$GITHUB_REPO" >/dev/null 2>&1; then
    gh release upload "$tag" "$VERSIONED_ZIP" --repo "$GITHUB_REPO" --clobber
    echo "GitHub: uploaded asset to existing release $tag"
  else
    gh release create "$tag" "$VERSIONED_ZIP" \
      --repo "$GITHUB_REPO" \
      --title "$title" \
      --notes "AJ Forms WordPress plugin release $VERSION"
    echo "GitHub: created release $tag and uploaded asset"
  fi
}

VERSION_OVERRIDE=""
BUMP_PART=""
NO_BUMP="false"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION_OVERRIDE="${2:-}"; shift 2 ;;
    --bump) BUMP_PART="${2:-}"; shift 2 ;;
    --no-bump) NO_BUMP="true"; shift ;;
    --slug) PLUGIN_SLUG="${2:-}"; shift 2 ;;
    --root)
      ROOT_DIR="$(cd "${2:-}" && pwd)"
      RELEASES_DIR="$ROOT_DIR/releases"
      PLUGIN_FILE="$ROOT_DIR/ajforms.php"
      shift 2
      ;;
    --repo) GITHUB_REPO="${2:-}"; shift 2 ;;
    --github-release) GITHUB_RELEASE="true"; shift ;;
    --no-github-release) GITHUB_RELEASE="false"; shift ;;
    --git-commit) GIT_COMMIT="true"; shift ;;
    --no-git-commit) GIT_COMMIT="false"; shift ;;
    --push) GIT_PUSH="true"; shift ;;
    --no-push) GIT_PUSH="false"; shift ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Error: unknown option $1" >&2; usage; exit 1 ;;
  esac
done

[[ -n "$PLUGIN_SLUG" ]] || { echo "Error: plugin slug cannot be empty" >&2; exit 1; }
[[ -n "$GITHUB_REPO" ]] || { echo "Error: GitHub repo cannot be empty" >&2; exit 1; }

require_files

CURRENT_VERSION="$(get_version)"
validate_version "$CURRENT_VERSION"

ACTION_COUNT=0
[[ -n "$VERSION_OVERRIDE" ]] && ACTION_COUNT=$((ACTION_COUNT + 1))
[[ -n "$BUMP_PART" ]] && ACTION_COUNT=$((ACTION_COUNT + 1))
[[ "$NO_BUMP" == "true" ]] && ACTION_COUNT=$((ACTION_COUNT + 1))

if (( ACTION_COUNT > 1 )); then
  echo "Error: use only one of --version, --bump, or --no-bump" >&2
  exit 1
fi

if [[ -n "$VERSION_OVERRIDE" ]]; then
  NEXT_VERSION="$VERSION_OVERRIDE"
elif [[ -n "$BUMP_PART" ]]; then
  NEXT_VERSION="$(bump_version "$CURRENT_VERSION" "$BUMP_PART")"
elif [[ "$NO_BUMP" == "true" ]]; then
  NEXT_VERSION="$CURRENT_VERSION"
else
  NEXT_VERSION="$(choose_version_from_menu "$CURRENT_VERSION")"
fi

validate_version "$NEXT_VERSION"

if [[ "$NEXT_VERSION" != "$CURRENT_VERSION" ]]; then
  set_version "$NEXT_VERSION"
fi

VERSION="$(get_version)"
validate_version "$VERSION"

mkdir -p "$RELEASES_DIR"
VERSIONED_ZIP="$RELEASES_DIR/$PLUGIN_SLUG-$VERSION.zip"
LATEST_ZIP="$RELEASES_DIR/$PLUGIN_SLUG.zip"

rm -f "$VERSIONED_ZIP" "$LATEST_ZIP"
rm -rf "$RELEASES_DIR/$PLUGIN_SLUG"

build_zip
verify_zip "$VERSIONED_ZIP"

if [[ "$GIT_COMMIT" == "ask" ]]; then
  if ask_yes_no "Commit version changes to git?" "y"; then
    GIT_COMMIT="true"
  else
    GIT_COMMIT="false"
  fi
fi

if [[ "$GIT_COMMIT" == "true" ]]; then
  git_commit_release_files
fi

if [[ "$GIT_PUSH" == "ask" ]]; then
  if ask_yes_no "Push branch and version tag to GitHub?" "y"; then
    GIT_PUSH="true"
  else
    GIT_PUSH="false"
  fi
fi

if [[ "$GIT_PUSH" == "true" ]]; then
  git_create_tag
  git_push_release
fi

if [[ "$GITHUB_RELEASE" == "ask" ]]; then
  if ask_yes_no "Create/update GitHub Release and upload zip?" "y"; then
    GITHUB_RELEASE="true"
  else
    GITHUB_RELEASE="false"
  fi
fi

if [[ "$GITHUB_RELEASE" == "true" ]]; then
  git_create_tag
  publish_github_release
fi

echo "Current version: $CURRENT_VERSION"
echo "New version: $VERSION"
echo "Updated: $PLUGIN_FILE"
echo "Built: $VERSIONED_ZIP"
echo "Built: $LATEST_ZIP"
echo ""
echo "Verified:"
echo "  $PLUGIN_SLUG/"
echo "  $PLUGIN_SLUG/ajforms.php"
echo ""
echo "Verify manually with:"
echo "  unzip -l \"$VERSIONED_ZIP\" | head"
