FROM php:8.2-fpm

# Install nginx and inotify-tools for file watching
RUN apt-get update && apt-get install -y nginx inotify-tools

RUN rm -f /etc/nginx/sites-enabled/default

# Set working directory
WORKDIR /var/www/html

# Copy PHP files
#COPY . /var/www/html/

# Copy nginx configuration
COPY nginx.conf /etc/nginx/conf.d/default.conf

# Set permissions for web root and PHP-FPM socket
RUN mkdir -p /var/run/php-fpm && \
    chown -R www-data:www-data /var/www/html && \
    chown -R www-data:www-data /var/run/php-fpm && \
    chmod 755 /var/www/html

# Create directory for Nginx pid file
RUN mkdir -p /run/nginx

# Create sync script
RUN echo "#!/bin/sh\n\nwhile true; do\n    inotifywait -r -e modify,create,delete,move --quiet /var/www/html\n    { nginx -s reload & kill -USR2 \"\$(cat /var/run/php-fpm/php-fpm.pid)\" & }\n    sleep 0.05\ndone" > /usr/local/bin/sync-reload.sh && \
    chmod +x /usr/local/bin/sync-reload.sh

# Create queue worker start script (copied and runs at runtime with auto-restart)
COPY start-worker.sh /usr/local/bin/start-worker.sh
#RUN chmod +x /usr/local/bin/start-worker.sh

RUN sed -i 's/\r$//' /usr/local/bin/start-worker.sh \
    && chmod +x /usr/local/bin/start-worker.sh

# Expose port 80
EXPOSE 80

# Configure PHP-FPM to use TCP/IP socket
# Install required PHP extensions
RUN apt-get update && apt-get install -y default-mysql-client libpq-dev libzip-dev zip && docker-php-ext-install pdo pdo_mysql zip opcache

# Install and enable PHP Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Configure PHP memory and performance settings
RUN echo "memory_limit = 512M\n\
max_execution_time = 120\n\
max_input_time = 120\n\
post_max_size = 50M\n\
upload_max_filesize = 50M\n\
max_file_uploads = 20\n\
output_buffering = 4096\n\
zend.assertions = -1\n\
opcache.enable = 1\n\
opcache.memory_consumption = 128\n\
opcache.interned_strings_buffer = 8\n\
opcache.max_accelerated_files = 4000\n\
opcache.revalidate_freq = 60\n\
opcache.fast_shutdown = 1\n\
opcache.enable_cli = 1" > /usr/local/etc/php/conf.d/custom.ini

# Configure PHP-FPM with optimized settings
RUN echo "[www]\n\
listen = 127.0.0.1:9000\n\
user = www-data\n\
group = www-data\n\
pm = dynamic\n\
pm.max_children = 75\n\
pm.start_servers = 8\n\
pm.min_spare_servers = 5\n\
pm.max_spare_servers = 15\n\
pm.max_requests = 1000\n\
request_terminate_timeout = 120s\n\
php_admin_value[memory_limit] = 512M\n\
php_flag[display_errors] = off\n\
php_admin_flag[log_errors] = on\n\
php_admin_value[error_log] = /proc/self/fd/2\n\
php_admin_value[max_input_vars] = 3000\n\
php_admin_value[max_execution_time] = 120\n\
php_admin_value[session.gc_maxlifetime] = 3600\n\
php_admin_value[date.timezone] = UTC" > /usr/local/etc/php-fpm.d/www.conf

# Set proper permissions and start services with file watching
# CMD sh -c "mkdir -p /var/run/php-fpm && \
#     chown -R www-data:www-data /var/www/html && \
#     chmod -R 755 /var/www/html && \
#     php-fpm -D && \
#     /usr/local/bin/start-worker.sh & \
#     /usr/local/bin/sync-reload.sh & \
#     nginx -g 'daemon off;'"
CMD ["sh", "-c", "mkdir -p /var/run/php-fpm && mkdir -p /var/www/html/storage/logs /var/www/html/storage/framework /var/www/html/bootstrap/cache && chown -R www-data:www-data /var/www/html /var/run/php-fpm && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache || true && php-fpm -D && /usr/local/bin/start-worker.sh & /usr/local/bin/sync-reload.sh & nginx -g 'daemon off;'"]
