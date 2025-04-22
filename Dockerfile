## This Dockerfile can also be used to deploy Sendy to Railway

# Use the PHP image with Apache
FROM php:8.2-apache

# Install required dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
	unzip \
	curl \
	gettext \
	&& docker-php-ext-install mysqli gettext calendar \
	&& docker-php-ext-enable mysqli gettext calendar \
	&& apt-get clean \
	&& rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set PHP configuration
COPY overrides/.user.ini /usr/local/etc/php/conf.d/sendy.ini
RUN mkdir -p /app/tmp && chmod 777 /app/tmp

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Set the working directory
WORKDIR /var/www/html

# Copy application files and scripts
COPY . .

# Update Apache configuration for /sendy as root and dynamic port
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/sendy|g' /etc/apache2/sites-available/000-default.conf \
	&& sed -i 's|<Directory /var/www/html>|<Directory /var/www/html/sendy>|g' /etc/apache2/apache2.conf \
	&& echo '<Directory /var/www/html/sendy>\n\
	Options Indexes FollowSymLinks\n\
	AllowOverride All\n\
	Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf \
	&& echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Ensure deploy script is executable
RUN chmod +x deploy.sh

# Start the server: run deploy.sh and launch Apache
CMD ["bash", "-c", "./deploy.sh && apache2-foreground"]