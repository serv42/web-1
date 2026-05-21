FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache nginx supervisor curl

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create working directory
WORKDIR /var/www/html

# Copy all website files into the image first
COPY . /var/www/html

# Install PHPMailer via Composer inside the image
RUN composer require phpmailer/phpmailer:^6.9 --no-interaction --update-no-dev --optimize-autoloader

# Copy nginx config
COPY nginx.conf /etc/nginx/http.d/default.conf

# Copy supervisord config
COPY supervisord.conf /etc/supervisord.conf

# Override PHP-FPM listen binding
COPY zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf

# Expose port
EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
