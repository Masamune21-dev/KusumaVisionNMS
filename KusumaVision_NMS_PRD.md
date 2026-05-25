# PRD — KusumaVision NMS

## FTTH Access Network Management Platform

Versi: 1.0  
Status: Draft Awal  
Owner: PT BERKAH MEDIA KUSUMA VISION (BMKV)  
Product Name: KusumaVision NMS

---

# 1. Executive Summary

KusumaVision NMS adalah platform modern FTTH Network Management System untuk ISP FTTH sebagai alternatif SmartOLT dan NetNumen.

Fokus utama:
- GPON OLT Management
- ONU Provisioning
- Optical Monitoring
- Alarm Monitoring
- Multi Vendor Support
- Realtime Dashboard
- FTTH Automation

---

# 2. Product Vision

Menjadi platform NMS FTTH modern, realtime, modular, scalable, dan mudah digunakan untuk ISP Indonesia.

---

# 3. Product Goals

## Primary Goals
- Multi OLT management
- ONU provisioning
- Optical monitoring
- Alarm monitoring
- Remote ONU management
- Topology visualization

## Secondary Goals
- AI analytics
- Auto healing
- Billing integration
- Ticketing integration

---

# 4. Core Features

## Dashboard
- ONU online/offline
- Alarm summary
- Optical health
- Traffic graph
- OLT health

## OLT Management
- Add/edit/delete OLT
- Vendor detection
- Chassis monitoring

## ONU Monitoring
- RX/TX optical
- Distance
- Temperature
- Voltage
- Dying Gasp
- LOS
- Last down cause

## ONU Provisioning
- Auto detect ONU
- Register ONU
- VLAN assignment
- TCONT assignment
- PPPoE configuration
- TR069 configuration

## Remote ONU Management
- Reboot ONU
- Enable/disable ONU
- WiFi config
- Remote management

## Alarm Engine
- ONU offline
- LOS
- Dying Gasp
- Uplink down
- High attenuation
- ONU flapping

---

# 5. Technical Architecture

## Frontend
- Vue 3
- Inertia.js
- TailwindCSS
- ApexCharts

## Backend
- Laravel 12
- Redis
- Horizon
- Reverb

## Polling Engine
- GoLang
- gosnmp
- SSH/Telnet client

## Database
- PostgreSQL
- TimescaleDB
- Redis

---

# 6. Database Design

Main tables:
- users
- roles
- olts
- pon_ports
- onus
- customers
- optical_metrics
- traffic_metrics
- alarm_events
- provisioning_logs

---

# 7. Deployment

## Infrastructure
- Ubuntu Server
- Nginx
- Docker-ready
- Supervisor/Systemd

---

# 8. Roadmap

## Phase 1
- OLT monitoring
- ONU monitoring
- Provisioning
- Alarm basic

## Phase 2
- MikroTik integration
- Radius integration
- Topology
- WhatsApp notification

## Phase 3
- AI analytics
- Predictive maintenance
- Auto healing

---

# 9. Branding

## Product Name
KusumaVision NMS

## Subtitle
Unified FTTH Network Management Platform
