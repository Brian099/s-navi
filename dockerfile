FROM php:8.2-fpm-alpine

# 安装 Nginx（Alpine 官方包）
RUN apk add --no-cache nginx supervisor

# 创建目录
RUN mkdir -p /var/www/html \
    && mkdir -p /run/nginx \
    && mkdir -p /var/log/supervisor

# 复制网站文件
COPY . /var/www/html/

# 设置权限，确保 PHP-FPM 可写
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 复制 nginx 配置
COPY docker/nginx.conf /etc/nginx/nginx.conf

# 复制 supervisor 配置（同时运行 Nginx + PHP-FPM）
COPY docker/supervisord.conf /etc/supervisord.conf

EXPOSE 80

# 启动 supervisor（管理 php-fpm 与 nginx）
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
