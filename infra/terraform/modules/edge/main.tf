# Borde de un entorno (`spec/07-devops/05-cloudflare.md`).

terraform {
  required_providers {
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 4.0"
    }
  }
}

resource "cloudflare_record" "api" {
  zone_id = var.zone_id
  name    = var.api_host
  type    = "A"
  content = var.origin_ip
  proxied = true
}

resource "cloudflare_record" "app" {
  zone_id = var.zone_id
  name    = var.app_host
  type    = "CNAME"
  content = var.frontend_origin
  proxied = true
}

resource "cloudflare_zone_settings_override" "main" {
  zone_id = var.zone_id

  settings {
    ssl                      = "strict"
    min_tls_version          = "1.2"
    tls_1_3                  = "on"
    always_use_https         = "on"
    opportunistic_encryption = "on"

    security_header {
      enabled            = true
      max_age            = 31536000
      include_subdomains = true
      preload            = true
    }
  }
}

resource "cloudflare_ruleset" "managed_waf" {
  zone_id = var.zone_id
  name    = "logistics managed waf"
  kind    = "zone"
  phase   = "http_request_firewall_managed"

  rules {
    action      = "execute"
    description = "Cloudflare Managed Ruleset"
    expression  = "true"
    enabled     = true

    action_parameters {
      id = "efb7b8c949ac4650a09736fc376e9aee"
    }
  }

  rules {
    action      = "execute"
    description = "OWASP Core Ruleset"
    expression  = "true"
    enabled     = true

    action_parameters {
      id = "4814384a9e5d4991b9815dcfc25d2f1f"
    }
  }
}

resource "cloudflare_ruleset" "custom_waf" {
  zone_id = var.zone_id
  name    = "logistics custom waf"
  kind    = "zone"
  phase   = "http_request_firewall_custom"

  rules {
    action      = "block"
    description = "Rutas que la plataforma no usa"
    expression  = "(http.request.uri.path contains \"/.env\") or (http.request.uri.path contains \"/wp-admin\") or (http.request.uri.path contains \"/.git\") or (http.request.uri.path contains \"/phpinfo\")"
    enabled     = true
  }

  rules {
    action      = "block"
    description = "Metricas solo dentro del cluster"
    expression  = "(http.request.uri.path eq \"/metrics\")"
    enabled     = true
  }

  rules {
    action      = "block"
    description = "Metodos no usados"
    expression  = "(http.request.method in {\"TRACE\" \"TRACK\"})"
    enabled     = true
  }
}

resource "cloudflare_ruleset" "rate_limits" {
  zone_id = var.zone_id
  name    = "logistics rate limits"
  kind    = "zone"
  phase   = "http_ratelimit"

  rules {
    action      = "managed_challenge"
    description = "Login"
    expression  = "(http.request.uri.path eq \"/api/v1/auth/login\" and http.request.method eq \"POST\")"
    enabled     = true

    ratelimit {
      characteristics     = ["ip.src", "cf.colo.id"]
      period              = 60
      requests_per_period = 10
      mitigation_timeout  = 60
    }
  }

  rules {
    action      = "block"
    description = "Recuperacion de contrasena"
    expression  = "(http.request.uri.path eq \"/api/v1/auth/forgot-password\" and http.request.method eq \"POST\")"
    enabled     = true

    ratelimit {
      characteristics     = ["ip.src", "cf.colo.id"]
      period              = 60
      requests_per_period = 3
      mitigation_timeout  = 60
    }
  }

  rules {
    action      = "block"
    description = "Global de la API por IP"
    expression  = "(http.host eq \"${var.api_host}\")"
    enabled     = true

    ratelimit {
      characteristics     = ["ip.src", "cf.colo.id"]
      period              = 60
      requests_per_period = var.api_requests_per_minute
      mitigation_timeout  = 60
    }
  }
}

resource "cloudflare_ruleset" "cache" {
  zone_id = var.zone_id
  name    = "logistics cache"
  kind    = "zone"
  phase   = "http_request_cache_settings"

  rules {
    action      = "set_cache_settings"
    description = "La API nunca se cachea"
    expression  = "(http.host eq \"${var.api_host}\")"
    enabled     = true

    action_parameters {
      cache = false
    }
  }

  rules {
    action      = "set_cache_settings"
    description = "Assets con hash del frontend"
    expression  = "(http.host eq \"${var.app_host}\" and starts_with(http.request.uri.path, \"/assets/\"))"
    enabled     = true

    action_parameters {
      cache = true

      edge_ttl {
        mode    = "override_origin"
        default = 31536000
      }
    }
  }
}

# Para el firewall del balanceador: solo se admite a Cloudflare.
data "cloudflare_ip_ranges" "current" {}
