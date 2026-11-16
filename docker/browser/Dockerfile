# Navegador do Radar de domínios (docker/browser/capture.mjs).
# Só o Chromium "headless shell" do Playwright: sem Firefox, WebKit nem interface.
FROM node:22-bookworm-slim

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    NODE_ENV=production

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund \
    && npx playwright-core install --with-deps --only-shell chromium \
    && rm -rf /root/.npm /var/lib/apt/lists/* /tmp/*

COPY capture.mjs loop.sh ./

USER node

# Commit que gerou a imagem (o deploy passa o SHA; fora dele fica "dev"). O
# ci/servidor/perfis.sh compara com ele para saber se a imagem precisa ser refeita.
ARG REVISION=dev
# secmon.manter: a imagem só tem container quando há pedido, e a limpeza diária do
# servidor (docker image prune -a) a apagaria; com --filter "label!=secmon.manter=1"
# no cron, ela fica.
LABEL org.opencontainers.image.revision=$REVISION \
      secmon.manter="1"

ENTRYPOINT ["node", "/app/capture.mjs"]
