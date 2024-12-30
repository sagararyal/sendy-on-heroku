#!/bin/bash
set -e

SENDY_URL="https://sendy.co/download/?license=${SENDY_LICENSE_CODE}"
SENDY_DIR="sendy"

if [ -n "$SENDY_VERSION" ] || [ -n "$SENDY_ARCHIVE_URL" ]; then
  # https://example.com/sendy/?version=6.1.2
  SENDY_URL="${SENDY_ARCHIVE_URL}?version=${SENDY_VERSION}"
fi

echo "Downloading Sendy..."
curl -L -o sendy.zip "$SENDY_URL"

# Verify the downloaded file
if [ ! -f "sendy.zip" ]; then
	echo "Error: Failed to download Sendy. Check your SENDY_LICENSE_CODE"
	exit 1
fi

echo "Extracting Sendy..."
unzip -q sendy.zip || { echo "Error: Failed to extract Sendy."; exit 1; }
rm sendy.zip
echo "Removed sendy.zip"

echo "deleteting en_US locale files from overrides"
rm -rf overrides/locale/en_US/*

echo "Overriding files..."
# Move overrides into the correct Sendy folder
if [ -d "$SENDY_DIR" ]; then
	cp -R overrides/* "$SENDY_DIR/"
	echo "Overrides copied to $SENDY_DIR/"

else
	echo "Error: Sendy directory not found."
	exit 1
fi

echo "Sendy folder successfully created and populated."

echo "Deployment complete!"
