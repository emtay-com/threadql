FROM emtaycom/threadql:latest

RUN apk add --update --no-cache supervisor

ADD supervisord.conf /etc/supervisor/supervisord.conf
ADD worker.conf /etc/supervisor/conf.d/worker.conf

ENTRYPOINT ["supervisord", "--nodaemon", "--configuration", "/etc/supervisor/supervisord.conf"]
