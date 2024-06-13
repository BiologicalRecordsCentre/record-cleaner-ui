#!/bin/sh

# Start SSH server
service ssh start

# Pass control to the original bitnami entrypoint.
exec /opt/bitnami/scripts/drupal/entrypoint.sh "$@"