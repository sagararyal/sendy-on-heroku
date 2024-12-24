#!/bin/bash
set -e

SENDY_URL="https://sendy.co/download/?license=${SENDY_LICENSE_CODE}"
SENDY_DIR="sendy"

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
