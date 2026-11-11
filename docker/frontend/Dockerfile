# syntax=docker/dockerfile:1
FROM ghcr.io/cirruslabs/flutter:stable AS build

ENV FLUTTER_STORAGE_BASE_URL=https://storage.flutter-io.cn

WORKDIR /app

COPY hestia_app/pubspec.yaml hestia_app/pubspec.lock ./
RUN --mount=type=cache,target=/root/.pub-cache,sharing=locked flutter pub get

COPY hestia_app/ ./
RUN --mount=type=cache,target=/root/.pub-cache,sharing=locked \
    flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000

FROM nginx:1.27-alpine

COPY docker/frontend/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/build/web /usr/share/nginx/html

EXPOSE 80
