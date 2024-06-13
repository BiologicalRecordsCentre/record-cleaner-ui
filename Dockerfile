# Adds an SSH server to the Bitnami image for remote debugging.
FROM bitnami/drupal:10
USER 0
RUN apt-get update && apt-get install -y openssh-server
RUN sed -i 's/PermitRootLogin prohibit-password/PermitRootLogin yes/g' /etc/ssh/sshd_config
EXPOSE 22
USER 1001
COPY docker-entrypoint.sh /
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD [ "/opt/bitnami/scripts/apache/run.sh" ]
