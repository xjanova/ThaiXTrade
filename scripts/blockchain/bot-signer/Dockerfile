# TPIX bot signer — build context = scripts/blockchain (ต้องเห็นทั้ง bot-signer/ และสคริปต์เซ็นตัวเดิม)
#
#   docker build -f scripts/blockchain/bot-signer/Dockerfile -t tpix-bot-signer scripts/blockchain
#
# โครงในอิมเมจตรงกับในรีโป: server.cjs หาสคริปต์เซ็นที่ ../bot-wallet-*.cjs
FROM node:20-alpine

WORKDIR /app/bot-signer

COPY bot-signer/package.json ./
RUN npm install --omit=dev --no-audit --no-fund && npm cache clean --force

COPY bot-signer/*.cjs ./
COPY bot-wallet-transfer.cjs bot-wallet-swap.cjs /app/

# สคริปต์เซ็นอยู่ที่ /app แต่ ethers อยู่ที่ /app/bot-signer/node_modules — ให้ทั้งสองที่หาเจอ
ENV NODE_PATH=/app/bot-signer/node_modules \
    SIGNER_STATE_DIR=/state \
    SIGNER_BIND=0.0.0.0:8787

RUN mkdir -p /state && chown node:node /state
USER node
VOLUME /state
EXPOSE 8787

CMD ["node", "server.cjs"]
