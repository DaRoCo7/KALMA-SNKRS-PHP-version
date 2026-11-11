FROM nginx:1.27-alpine

# El entrypoint de Nginx genera default.conf con el destino HTTPS del entorno.
ENV NGINX_ENVSUBST_FILTER=^KALMA_HTTPS_ORIGIN$
COPY docker/nginx/default.conf /etc/nginx/templates/default.conf.template
COPY public/css/ /var/www/html/public/css/
COPY public/js/ /var/www/html/public/js/
COPY public/img/ /var/www/html/public/img/
