FROM ghcr.io/cirruslabs/flutter:stable AS build

WORKDIR /app

COPY hestia_app/pubspec.yaml hestia_app/pubspec.lock ./
RUN flutter pub get

COPY hestia_app/ ./
RUN flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000

FROM nginx:1.27-alpine

COPY --from=build /app/build/web /usr/share/nginx/html

EXPOSE 80
