#!/bin/bash
set -e

# Detect Heroku App Name
if [ -z "$HEROKU_APP_NAME" ]; then
	echo "Error: HEROKU_APP_NAME is not set."
	exit 1
fi

echo "Adding tasks to Heroku Scheduler for $HEROKU_APP_NAME..."

# Add tasks
heroku run "php /app/sendy/scheduled.php > /dev/null 2>&1" --app "$HEROKU_APP_NAME"
heroku run "php /app/sendy/autoresponders.php > /dev/null 2>&1" --app "$HEROKU_APP_NAME"
heroku run "php /app/sendy/import-csv.php > /dev/null 2>&1" --app "$HEROKU_APP_NAME"

echo "Heroku Scheduler tasks added successfully!"