FROM node:22-alpine AS swagger

WORKDIR /workspace
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

FROM nginx:alpine

COPY frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY frontend/ /usr/share/nginx/html
COPY --from=swagger /workspace/node_modules/swagger-ui-dist /usr/share/nginx/html/swagger-ui
COPY --from=swagger /workspace/node_modules/@google/model-viewer/dist/model-viewer.min.js /usr/share/nginx/html/model-viewer.min.js

EXPOSE 80
