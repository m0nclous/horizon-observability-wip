docker run -d --name ho-grafana --network host \
  -e GF_SERVER_HTTP_PORT=3301 -e GF_SECURITY_ADMIN_PASSWORD=admin \
  -e GF_AUTH_ANONYMOUS_ENABLED=true -e GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer \
  -e GF_PLUGINS_PREINSTALL_SYNC=yesoreyeram-infinity-datasource@4.1.1,marcusolsson-dynamictext-panel@6.3.0 \
  -e HTTPS_PROXY=http://127.0.0.1:32939 -e NO_PROXY=127.0.0.1,localhost \
  -e SSL_CERT_FILE=/etc/ssl/ccr-ca.crt -v /root/.ccr/ca-bundle.crt:/etc/ssl/ccr-ca.crt:ro \
  -v /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/design/addendum/stack/grafana/provisioning:/etc/grafana/provisioning:ro -v /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/design/addendum/stack/grafana/dashboards:/etc/grafana/dashboards:ro -v /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/design/addendum/stack/grafana/data:/var/lib/grafana \
  "$@" grafana/grafana:latest
