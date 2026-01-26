#!/bin/bash

##
# Release Script for Unlimited CDN
# Creates a clean WordPress.org-ready zip file
##

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get the plugin directory (where this script is located)
PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PLUGIN_SLUG="bandwidth-saver"  # WordPress.org slug (for zip naming)
FOLDER_NAME=$(basename "${PLUGIN_DIR}")  # Current folder name (for build structure)
BUILD_DIR="/tmp/${FOLDER_NAME}"
OUTPUT_DIR="$(dirname "${PLUGIN_DIR}")"

echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Unlimited CDN - Release Build${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Extract version from main plugin file
VERSION=$(grep "^ \* Version:" "${PLUGIN_DIR}/imgpro-cdn.php" | head -1 | awk '{print $3}' | tr -d '\r\n ')

if [ -z "$VERSION" ]; then
    echo -e "${RED}ERROR: Could not extract version from imgpro-cdn.php${NC}"
    exit 1
fi

echo -e "Plugin Version: ${YELLOW}${VERSION}${NC}"
echo ""

# Clean up previous build
echo -e "${YELLOW}→${NC} Cleaning up previous build..."
rm -rf "${BUILD_DIR}"
rm -f "${OUTPUT_DIR}/${PLUGIN_SLUG}.zip"
rm -f "${OUTPUT_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

# Create fresh build directory
echo -e "${YELLOW}→${NC} Creating build directory..."
mkdir -p "${BUILD_DIR}"

# Build rsync exclusions from .distignore file
RSYNC_EXCLUDES=""
EXCLUSION_COUNT=0
if [ -f "${PLUGIN_DIR}/.distignore" ]; then
  echo -e "${YELLOW}→${NC} Reading exclusions from .distignore..."
  while IFS= read -r line || [ -n "$line" ]; do
    # Skip empty lines and comments
    if [[ ! "$line" =~ ^[[:space:]]*$ ]] && [[ ! "$line" =~ ^[[:space:]]*# ]]; then
      # Trim whitespace
      pattern=$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
      RSYNC_EXCLUDES="${RSYNC_EXCLUDES} --exclude=${pattern}"
      ((EXCLUSION_COUNT++))
    fi
  done < "${PLUGIN_DIR}/.distignore"
  echo -e "  ${GREEN}✓${NC} Loaded ${EXCLUSION_COUNT} exclusion patterns"
else
  echo -e "${RED}Warning: .distignore file not found${NC}"
  echo -e "${YELLOW}→${NC} Using default exclusions..."
  RSYNC_EXCLUDES="--exclude=.git --exclude=.gitignore --exclude=.DS_Store --exclude=release.sh --exclude=*.zip"
fi

# Copy files using rsync with exclusions
echo -e "${YELLOW}→${NC} Copying plugin files..."
eval rsync -av ${RSYNC_EXCLUDES} "${PLUGIN_DIR}/" "${BUILD_DIR}/" > /dev/null

# Show what's included
echo -e "${YELLOW}→${NC} Files included in build:"
find "${BUILD_DIR}" -type f | sed "s|${BUILD_DIR}/|  • |" | sort

# Create zip file
echo ""
echo -e "${YELLOW}→${NC} Creating zip archive..."
cd /tmp
zip -r "${PLUGIN_SLUG}.zip" "${FOLDER_NAME}" > /dev/null

# Move to output directory with version
mv "/tmp/${PLUGIN_SLUG}.zip" "${OUTPUT_DIR}/${PLUGIN_SLUG}.zip"
cp "${OUTPUT_DIR}/${PLUGIN_SLUG}.zip" "${OUTPUT_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"

# Get file size
FILE_SIZE=$(ls -lh "${OUTPUT_DIR}/${PLUGIN_SLUG}.zip" | awk '{print $5}')

# Clean up temp directory
rm -rf "${BUILD_DIR}"

echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✓ Build complete!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "📦 Package: ${YELLOW}${PLUGIN_SLUG}.zip${NC}"
echo -e "📦 Versioned: ${YELLOW}${PLUGIN_SLUG}-${VERSION}.zip${NC}"
echo -e "📏 Size: ${YELLOW}${FILE_SIZE}${NC}"
echo -e "📍 Location: ${YELLOW}${OUTPUT_DIR}/${NC}"
echo ""
echo -e "${GREEN}Ready for WordPress.org submission!${NC}"
echo ""

# Ask if user wants to deploy to SVN
echo -e "${YELLOW}Deploy to WordPress.org SVN?${NC}"
echo -e "1) Yes - Deploy to SVN"
echo -e "2) No - Just create zip"
read -p "Choose [1-2]: " DEPLOY_CHOICE

if [ "$DEPLOY_CHOICE" = "1" ]; then
    echo ""
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${GREEN}  SVN Deployment${NC}"
    echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    # Get SVN directory
    DEFAULT_SVN_DIR="${HOME}/svn/bandwidth-saver"
    read -p "SVN directory path [${DEFAULT_SVN_DIR}]: " SVN_DIR
    SVN_DIR="${SVN_DIR:-$DEFAULT_SVN_DIR}"

    # Check if SVN directory exists
    if [ ! -d "$SVN_DIR" ]; then
        echo -e "${YELLOW}→${NC} SVN directory not found. Checking out..."
        mkdir -p "$(dirname "$SVN_DIR")"
        svn checkout https://plugins.svn.wordpress.org/bandwidth-saver/ "$SVN_DIR"
        if [ $? -ne 0 ]; then
            echo -e "${RED}ERROR: Failed to checkout SVN repository${NC}"
            exit 1
        fi
    fi

    echo -e "${YELLOW}→${NC} Cleaning trunk..."
    rm -rf "${SVN_DIR}/trunk/"*

    echo -e "${YELLOW}→${NC} Extracting files to trunk..."
    unzip -q "${OUTPUT_DIR}/${PLUGIN_SLUG}.zip" -d "/tmp/"
    rsync -av --delete "/tmp/${FOLDER_NAME}/" "${SVN_DIR}/trunk/" > /dev/null
    rm -rf "/tmp/${FOLDER_NAME}"

    # Copy WordPress.org assets if they exist (replace mode)
    if [ -d "${PLUGIN_DIR}/assets/.wordpress-org" ]; then
        echo -e "${YELLOW}→${NC} Syncing WordPress.org assets (replace mode)..."
        rsync -av --delete "${PLUGIN_DIR}/assets/.wordpress-org/" "${SVN_DIR}/assets/" > /dev/null
        echo -e "  ${GREEN}✓${NC} Assets synced (banners, icons, screenshots)"
    fi

    echo -e "${YELLOW}→${NC} Checking SVN status..."
    cd "$SVN_DIR"

    # Add new files in trunk
    svn status trunk | grep "^?" | awk '{print $2}' | xargs -I {} svn add {} 2>/dev/null || true

    # Remove deleted files in trunk
    svn status trunk | grep "^!" | awk '{print $2}' | xargs -I {} svn rm {} 2>/dev/null || true

    # Add new files in assets
    svn status assets | grep "^?" | awk '{print $2}' | xargs -I {} svn add {} 2>/dev/null || true

    # Remove deleted files in assets
    svn status assets | grep "^!" | awk '{print $2}' | xargs -I {} svn rm {} 2>/dev/null || true

    echo ""
    echo -e "${YELLOW}SVN Status:${NC}"
    svn status

    echo ""
    echo -e "${YELLOW}Next steps:${NC}"
    echo -e "1. Review changes: ${GREEN}cd ${SVN_DIR} && svn status${NC}"
    echo -e "2. Commit all changes: ${GREEN}svn ci -m \"Update to version ${VERSION}\"${NC}"
    echo -e "3. Create tag: ${GREEN}svn cp trunk tags/${VERSION}${NC}"
    echo -e "4. Commit tag: ${GREEN}svn ci -m \"Tagging version ${VERSION}\"${NC}"
    echo ""
    echo -e "${YELLOW}Or use these combined commands:${NC}"
    echo -e "${GREEN}cd ${SVN_DIR} && svn ci -m \"Update to version ${VERSION}\" && svn cp trunk tags/${VERSION} && svn ci -m \"Tagging version ${VERSION}\"${NC}"
    echo ""
    echo -e "${YELLOW}Assets included:${NC}"
    if [ -d "${SVN_DIR}/assets" ] && [ "$(ls -A ${SVN_DIR}/assets 2>/dev/null)" ]; then
        ls -1 "${SVN_DIR}/assets" | sed 's/^/  • /'
    else
        echo -e "  ${YELLOW}(none)${NC}"
    fi
    echo ""
fi
