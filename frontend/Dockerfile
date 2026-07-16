# =============================================================================
# FleetView frontend + web tier — production image.
# Multi-stage: (1) build the CRA static bundle  →  (2) nginx that serves the
# SPA AND reverse-proxies the Laravel API (php-fpm) + uploaded media.
# There is no Node runtime in production — the build output is fully static.
# =============================================================================

# ---- Stage 1: build the React (CRA) bundle ---------------------------------
FROM node:20-alpine AS build

WORKDIR /app

# API base URL is baked in at build time by Create React App. Default is the
# same-origin path (/api) — nginx proxies it to the backend. Override with
# --build-arg REACT_APP_API_URL=https://api.example.com/api for a split host.
ARG REACT_APP_API_URL=/api
ENV REACT_APP_API_URL=$REACT_APP_API_URL

# Install deps from the lockfile first (cached until package files change).
COPY package.json package-lock.json ./
RUN npm ci

# Build the optimized production bundle.
COPY . .
RUN npm run build

# ---- Stage 2: nginx web tier -----------------------------------------------
FROM nginx:1.27-alpine AS web

# Static SPA.
COPY --from=build /app/build /usr/share/nginx/html

# Server config: SPA fallback + /api,/sanctum,/up → php-fpm + /storage media.
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
