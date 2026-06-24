FROM ghcr.io/cirruslabs/flutter:stable AS build

ENV FLUTTER_STORAGE_BASE_URL=https://storage.flutter-io.cn

WORKDIR /app

COPY hestia_app/pubspec.yaml hestia_app/pubspec.lock ./
RUN flutter pub get

COPY hestia_app/ ./
RUN for attempt in 1 2 3 4 5; do \
        flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000 \
        && break; \
        if [ "$attempt" -eq 5 ]; then exit 1; fi; \
        sleep 10; \
    done

FROM nginx:1.27-alpine

COPY --from=build /app/build/web /usr/share/nginx/html

EXPOSE 80
