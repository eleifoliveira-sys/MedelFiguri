FROM node:22-alpine AS frontend-assets

WORKDIR /workspace
COPY api/package.json api/package-lock.json ./
RUN npm ci --omit=dev

FROM nginx:alpine

COPY frontend/nginx.conf /etc/nginx/conf.d/default.conf
COPY frontend/ /usr/share/nginx/html
COPY --from=frontend-assets /workspace/node_modules/@google/model-viewer/dist/model-viewer.min.js /usr/share/nginx/html/model-viewer.min.js

EXPOSE 80
