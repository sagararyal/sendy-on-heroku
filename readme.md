# Sendy on Heroku / Docker / Coolify

Deploy [Sendy](https://sendy.co/?ref=pJY2W) – the self-hosted email newsletter application on Heroku with minimal setup. Sendy lets you send trackable emails via Amazon Simple Email Service (SES), enabling you to send bulk emails. 

Be it for GDPR compliance, privacy, or for cost savings, Sendy allows you to send up to 10,000 emails for just $1, thanks to Amazon SES. You can also use your own SMTP.

[![Deploy to Heroku](https://www.herokucdn.com/deploy/button.svg)](https://dashboard.heroku.com/new?template=https://github.com/sagararyal/sendy-on-heroku/tree/main)

If you don't have a license key, you can get one [by clicking here](https://sendy.co/?ref=pJY2W).

<a href="https://sendy.co/?ref=pJY2W" title=""><img src="https://sendy.co/images/banners/728x90_var2.jpg" alt="Check out Sendy, a self hosted newsletter app that lets you send emails 100x cheaper via Amazon SES." width="728" height="90"/></a>

### Looking for a Setup Guide?
- Check this [Youtube Video](https://youtu.be/7r15Lemb86A) for step by step guide on Heroku deployment with Cloudflare R2.
- Check [Sendy documentation](https://sendy.co/get-started#step5) for other issue, including AWS SES setup.

## Features
- Support S3-compatible storage for file uploads using the AWS SDK. I personally use Cloudflare R2 for my projects.
- Automatic deployment of the latest version of Sendy.
- Cron job integration (e.g., autoresponders and email queue processing) can be added using Heroku Scheduler.

## Updates

To update Sendy on Heroku, fork this repository to your GitHub account. 

Log in to the [Heroku dashboard](https://dashboard.heroku.com/apps/), go to your app, and connect your forked repository under the Deploy tab by selecting GitHub as the deployment method. 

Finally, select the main branch and click Deploy Branch to update your Sendy instance to the latest version.

Each new deployment automatically downloads latest version of Sendy.co

---

### Required Environment Variables

Heroku will require the following environment variables during deployment. I opted-out of using heroku addon for bucketeer as it it only supports pre-signed URLs. I will add a guide on how to setup Cloudflare R2 easily.

| Variable               | Description                                                                 |
|------------------------|-----------------------------------------------------------------------------|
| `SENDY_LICENSE_CODE`   | Your Sendy license code (required to download the latest version of Sendy). |
| `DATABASE_URL`         | The database connection URL (MySQL).                                       |
| `S3_ENDPOINT`         | The endpoint URL for AWS S3-compatible storage (e.g., Cloudflare R2). Defaults to `https://s3.amazonaws.com` if not set. |
| `S3_ACCESS_KEY_ID`    | AWS S3-compatible storage access key.                                      |
| `S3_SECRET_ACCESS_KEY`| AWS S3-compatible storage secret key.                                      |
| `S3_BUCKET_NAME`      | The name of the S3-compatible storage bucket.                              |
| `S3_REGION`           | The region for your S3-compatible storage.                                 |
| `S3_CDN_URL`          | CDN / PUBLIC URL of your S3-compatible storage.                            |
| `APP_PATH`             | The public-facing URL of your Heroku app (e.g., `https://your-app.herokuapp.com`). |
---

To run a custom version, provide a publicly accessible URL to a file named sendy.zip, for example: `https://example.com/sendy.zip`.

To enable this, add the following environment variables to your Heroku environment configuration: `SENDY_ARCHIVE_URL` & `SENDY_VERSION`
 
## Contributions & Disclaimer

Contributions are welcome! To extend features or suggest changes, open a pull request. Overrides to the existing `sendy/` directory should be handled using a deploy script or added to the `overrides` folder.

You can run `heroku local -f Procfile.local` to deploy this app locally. Ensure you have php, composer, and heroku-cli, pre-installed.

PS.The links in this repository are affiliate links, which help maintain this project at no additional cost to you. If you’d like to support my work, consider making a [donation to Plant-for-the-Planet Foundation](https://github.com/sponsors/Plant-for-the-Planet-org), where I work.

ChatGPT has generated some parts of code for this repo.
