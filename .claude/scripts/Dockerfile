FROM node:24-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205
WORKDIR /app
ENV TZ=America/Sao_Paulo \
    WALLE_RAG_DIR=/knowledge \
    WALLE_RAG_CACHE=/models \
    ONNXRUNTIME_NODE_INSTALL_CUDA=skip
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN mkdir -p /knowledge /models && chown node:node /knowledge /models
USER node
CMD ["node", "worker.ts"]
