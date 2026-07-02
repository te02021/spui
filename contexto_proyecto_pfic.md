# Contexto del Proyecto: Sistema Integral de Pantallas Informativas (UNRaf)

## 1. Descripción General
El objetivo de este proyecto es desarrollar un prototipo funcional de un "Sistema Integral de Cartelería Digital" (Digital Signage) con arquitectura distribuida cliente-servidor para la Universidad Nacional de Rafaela (UNRaf) [3, 4]. 
El sistema centraliza la gestión de contenidos multimedia a través de un CMS alojado en la nube y distribuye dichos contenidos a múltiples nodos clientes (pantallas operadas por placas Raspberry Pi) distribuidas en el campus universitario [4, 5].

## 2. Stack Tecnológico Obligatorio
Para asegurar la compatibilidad con la infraestructura tecnológica de la UNRaf, el desarrollo debe apegarse estrictamente al siguiente stack tecnológico [1]:
*   **Backend / Panel CMS:** PHP con el framework Symfony.
*   **Base de Datos:** MySQL (Modelo Entidad-Relación relacional).
*   **Sistema Operativo del Servidor:** Linux (Entorno tipo LAMP).
*   **Nodos Cliente (Hardware):** Raspberry Pi corriendo un SO basado en Linux.
*   **Software Cliente (Player):** Python y scripts en Bash para la reproducción de contenido y control de hardware [1].
*   **Comunicación de Telemetría:** Protocolo MQTT [2].
*   **Comunicación de Contenido:** API REST (HTTP/HTTPS) o WebSockets [6].

## 3. Requerimientos Condicionantes del Jurado (CRÍTICOS)
El código y la arquitectura generada DEBEN contemplar obligatoriamente las siguientes correcciones impuestas por el tribunal evaluador:

1.  **Resiliencia y Modo Offline (Fail-safe):** Es obligatorio implementar una estrategia de caching local en las Raspberry Pi. Si el nodo pierde conexión de red con el CMS central, debe gestionar la persistencia de los contenidos y seguir mostrando información en pantalla [7-9].
2.  **Telemetría de Hardware (IoT):** Los nodos no son simples reproductores de video, son nodos IoT. Deben enviar periódicamente métricas preventivas (temperatura del SoC, uso de memoria RAM, latencia de red) hacia un tablero de monitoreo usando el protocolo MQTT [2, 8, 9].
3.  **Seguridad y Hardening:** Al ser un sistema de difusión pública, la API y los clientes deben incluir mecanismos robustos de seguridad para evitar la inyección de contenidos no autorizados por parte de terceros en la red [7, 9].

## 4. Arquitectura y Funcionalidades Principales

### 4.1. Panel de Administración Central (CMS en Symfony)
*   Debe permitir la carga, edición y publicación remota de contenidos multiformato (texto, imágenes, videos) [10].
*   **Segmentación:** El contenido debe poder asignarse según la ubicación de la pantalla (edificio/aula) o por franjas horarias (Scheduling) [10-12].
*   **Automatización:** El CMS debe poder programar el encendido, apagado y reducción automática de brillo de las pantallas para optimizar el consumo energético [10, 13].

### 4.2. Módulo de Alertas de Emergencia
*   El sistema debe contar con la capacidad de emitir avisos prioritarios y urgentes [11].
*   Estas alertas deben poder activar señales sonoras y visuales, y su diseño debe sobrescribir/interrumpir inmediatamente el contenido habitual que se esté reproduciendo en los clientes [11, 14].

### 4.3. Interacción con Usuarios
*   El sistema de renderizado en las Raspberry Pi debe soportar la incorporación y visualización de códigos QR interactivos y dinámicos para que los estudiantes accedan a formularios o información extra desde sus celulares [11, 14, 15].

## 5. Instrucciones para el Asistente de Código (IA)
*   **Prioridad:** El desarrollo actual se encuentra en la "Fase 3: Desarrollo y Configuración" [16]. Todo el código generado debe estar fuertemente tipado, comentado, modularizado y seguir las mejores prácticas y patrones de diseño de Symfony y Python.
*   **Seguridad:** En cada endpoint de la API diseñado, incluye autenticación y validación de datos para cumplir con el requisito de *hardening* [9].
*   **Telemetría:** Cuando se escriba el cliente en Python para la Raspberry, recuerda importar e implementar un cliente MQTT (ej. `paho-mqtt`) para el envío del estado del hardware [8].

## 6. Intenciones, Justificación y Soberanía Tecnológica
El prototipo nace para resolver problemas críticos de la Universidad Nacional de Rafaela (UNRaf): la desactualización de información, la falta de inmediatez y los costos operativos de las carteleras físicas y redes sociales [1, 2]. 
A diferencia de soluciones comerciales genéricas del mercado (como Yodeck, Screenly o NoviSign), las cuales requieren hardware propietario o suscripciones premium para analíticas y automatización [3, 4], este desarrollo propio busca **soberanía tecnológica**. La intención es lograr una integración nativa con los sistemas administrativos de la UNRaf, evitando depender de licencias externas y asegurando la privacidad institucional [5, 6].

## 7. Funcionalidades Detalladas del Prototipo a Desarrollar

### 7.1. Panel Central de Administración (CMS Web)
El CMS es el núcleo lógico del sistema alojado en la nube y debe cumplir con las siguientes funcionalidades operativas [7]:
*   **Gestión Multiformato:** Capacidad de carga, edición, programación y distribución centralizada de archivos multimedia variados (texto, imágenes, videos) [7, 8].
*   **Segmentación de Contenidos:** El administrador debe poder enviar mensajes específicos filtrando por la ubicación física de la pantalla (edificios o aulas concretas) o por franjas horarias determinadas [8, 9].
*   **Automatización Inteligente (Scheduling):** El panel debe permitir programar horarios para el encendido y apagado de las pantallas, además de la reducción automática del brillo, garantizando así la optimización de recursos y el ahorro de consumo energético [8, 9].

### 7.2. Módulo de Alertas de Emergencia Institucional
*   **Avisos Prioritarios y Override:** Funcionalidad crítica que permite al administrador emitir de forma inmediata avisos urgentes (situaciones de emergencia, evacuación o cambios de último momento) [10]. 
*   **Comportamiento Visual y Sonoro:** Al recibir la alerta, el nodo cliente debe sobrescribir/interrumpir inmediatamente el contenido habitual que se esté reproduciendo y activar señales visuales y sonoras de advertencia [10-12].

### 7.3. Interacción y Usabilidad (Módulo QR)
*   **Interactividad dinámica:** Como las pantallas no son táctiles, el sistema debe generar e integrar códigos QR dinámicos en los diseños mostrados [11, 12].
*   **Objetivo:** Permitir a los estudiantes o visitantes escanear el QR con sus dispositivos móviles para acceder a formularios, encuestas, o información académica complementaria que no cabe en la pantalla [10, 11].

### 7.4. Nodos Cliente (Hardware y Software en Raspberry Pi)
Las pantallas no actuarán como simples monitores, sino como **Nodos IoT** dentro de una arquitectura de sistemas distribuidos [13, 14]. El software desarrollado para los clientes debe tener:
*   **Resiliencia y Modo Offline (Fail-safe):** Requerimiento estricto del jurado. El cliente debe incorporar una estrategia de *caching* local. Si el nodo pierde la conexión de red o hay microcortes con el servidor CMS central, debe gestionar la persistencia y seguir mostrando los contenidos descargados sin mostrar pantallas de error [15-17].
*   **Telemetría Preventiva (Vía MQTT):** Cada nodo Raspberry Pi debe enviar métricas de su estado de hardware a un tablero de monitoreo. Obligatoriamente se deben medir y transmitir variables como la temperatura del SoC (procesador), el uso de la memoria RAM y la latencia de red [16-18].
*   **Seguridad y Hardening:** Al ser dispositivos de difusión pública en los pasillos de la UNRaf, el cliente y la API deben contar con barreras de seguridad perimetral y validación estricta para bloquear cualquier intento de inyección de contenidos no autorizados por terceros [15, 17, 18].