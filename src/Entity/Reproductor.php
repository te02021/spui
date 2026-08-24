<?php

declare(strict_types=1);

namespace SPUI\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SPUI\Enum\EstadoConexion;
use SPUI\Repository\ReproductorRepository;

#[ORM\Entity(repositoryClass: ReproductorRepository::class)]
#[ORM\Table(name: 'reproductor')]
class Reproductor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $hostname;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $versionFirmware = null;

    /** SHA-256 de la API key generada — nunca se almacena la key en claro */
    #[ORM\Column(length: 64)]
    private string $apiKeyHash;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $ultimoHeartbeat = null;

    #[ORM\Column(enumType: EstadoConexion::class)]
    private EstadoConexion $estadoConexion = EstadoConexion::SinRegistrar;

    /**
     * Problemas que el propio reproductor detecta y reporta en cada heartbeat.
     *
     * Existe para que nada falle en silencio: un equipo puede estar conectado y
     * sincronizando, pero sin mostrar nada en pantalla (por ejemplo si VLC no
     * encuentra el entorno gráfico). Sin esto, la única forma de enterarse era
     * entrar por SSH a leer el journal.
     *
     * Formato: lista de strings ya redactados para el operador.
     * Vacío = el reproductor no reporta problemas.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $diagnostico = null;

    /** Cuándo se recibió el último diagnóstico (para no mostrar avisos viejos). */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $diagnosticoEn = null;

    #[ORM\OneToMany(mappedBy: 'reproductor', targetEntity: Pantalla::class)]
    private Collection $pantallas;

    #[ORM\OneToMany(mappedBy: 'reproductor', targetEntity: Telemetria::class)]
    private Collection $telemetrias;

    public function __construct()
    {
        $this->pantallas   = new ArrayCollection();
        $this->telemetrias = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getVersionFirmware(): ?string { return $this->versionFirmware; }
    public function setVersionFirmware(?string $v): static { $this->versionFirmware = $v; return $this; }

    public function getApiKeyHash(): string { return $this->apiKeyHash; }
    public function setApiKeyHash(string $hash): static { $this->apiKeyHash = $hash; return $this; }

    public function getUltimoHeartbeat(): ?DateTimeImmutable { return $this->ultimoHeartbeat; }

    public function registrarHeartbeat(): static
    {
        $this->ultimoHeartbeat = new DateTimeImmutable();
        $this->estadoConexion  = EstadoConexion::Conectado;
        return $this;
    }

    /** @return string[] */
    public function getDiagnostico(): array { return $this->diagnostico ?? []; }

    /** @param string[] $problemas Lista vacía = el reproductor está sano. */
    public function setDiagnostico(array $problemas): static
    {
        // Se normaliza a null cuando no hay nada que reportar, para poder
        // distinguir "sin problemas" de "nunca reportó" (columna nullable).
        $limpios = array_values(array_filter(array_map('trim', $problemas)));

        $this->diagnostico   = $limpios !== [] ? $limpios : null;
        $this->diagnosticoEn = new DateTimeImmutable();

        return $this;
    }

    public function getDiagnosticoEn(): ?DateTimeImmutable { return $this->diagnosticoEn; }

    /** True si el reproductor reportó algún problema en su último heartbeat. */
    public function tieneProblemas(): bool
    {
        return $this->diagnostico !== null && $this->diagnostico !== [];
    }

    public function getEstadoConexion(): EstadoConexion { return $this->estadoConexion; }
    public function setEstadoConexion(EstadoConexion $e): static { $this->estadoConexion = $e; return $this; }

    /**
     * Estado de conexión DERIVADO del último heartbeat.
     *
     * Esta es la fuente de verdad, no la columna estado_conexion.
     *
     * El problema del campo persistido: registrarHeartbeat() sólo sabe escribir
     * "conectado", así que pasar a "desconectado" dependía de que un comando
     * externo corriera y lo notara. Si ese cron no estaba agendado —que fue el
     * caso durante meses— un equipo apagado figuraba conectado indefinidamente,
     * y el panel mentía sin que nada lo delatara.
     *
     * Calculándolo, el dato no puede quedar obsoleto: se deduce de la última
     * marca de tiempo cada vez que se lo consulta. La columna se mantiene
     * sincronizada por compatibilidad (consultas SQL, la API REST), pero para
     * mostrar en pantalla siempre gana este método.
     *
     * @param int $umbralSegundos Ver spui_umbral_conexion_default en services.yaml.
     */
    public function estadoCalculado(int $umbralSegundos): EstadoConexion
    {
        // Sin ningún heartbeat el equipo nunca se comunicó: no está
        // "desconectado" (eso implica que alguna vez estuvo conectado), sino
        // que todavía no se registró.
        if ($this->ultimoHeartbeat === null) {
            return EstadoConexion::SinRegistrar;
        }

        $limite = new DateTimeImmutable(sprintf('-%d seconds', $umbralSegundos));

        return $this->ultimoHeartbeat > $limite
            ? EstadoConexion::Conectado
            : EstadoConexion::Desconectado;
    }

    /** Segundos transcurridos desde el último heartbeat, o null si nunca reportó. */
    public function segundosDesdeHeartbeat(): ?int
    {
        if ($this->ultimoHeartbeat === null) {
            return null;
        }

        return (new DateTimeImmutable())->getTimestamp() - $this->ultimoHeartbeat->getTimestamp();
    }

    public function getPantallas(): Collection { return $this->pantallas; }
    public function getTelemetrias(): Collection { return $this->telemetrias; }

    public function verificarApiKey(string $rawKey): bool
    {
        return hash('sha256', $rawKey) === $this->apiKeyHash;
    }

    // ── Credenciales MQTT ────────────────────────────────────────────────────

    /**
     * Usuario con el que este reproductor se conecta al broker.
     *
     * También es el identificador que usan las ACLs: los permisos se escriben
     * como patrones sobre %u (spui/telemetria/%u), así que cada equipo sólo
     * puede publicar en sus propios topics.
     */
    public function mqttUsuario(): string
    {
        return self::mqttUsuarioDeId($this->id);
    }

    /**
     * Igual que mqttUsuario() pero sin necesitar la entidad, para cuando hay
     * que resolver el usuario a partir de un id suelto (por ejemplo al parsear
     * el topic de un mensaje entrante).
     */
    public static function mqttUsuarioDeId(int $id): string
    {
        return 'spui-repro-' . $id;
    }

    /**
     * Contraseña MQTT derivada de la API key.
     *
     * Deliberadamente NO es un secreto independiente. Si la Pi tuviera dos
     * credenciales distintas en su .env, se podrían desincronizar por separado
     * — que es exactamente lo que rompió la comunicación el 11/08: se regeneró
     * la API key desde el CMS y el .env quedó con la vieja, con el reproductor
     * devolviendo 401 en silencio durante días.
     *
     * Derivándola, el .env de la Pi sigue teniendo un solo secreto y regenerar
     * la API key regenera todo el vínculo de una sola vez.
     *
     * El salt (SPUI_MQTT_SALT) cumple dos funciones: que quien vea la base no
     * pueda derivar las contraseñas MQTT, y que esta contraseña no sea idéntica
     * al api_key_hash que ya está almacenado en la fila.
     *
     * OJO: cambiar el salt invalida las credenciales MQTT de TODOS los
     * reproductores y hay que recrear los clientes en el broker.
     *
     * @param string $rawKey La API key en claro. Sólo se conoce en el momento
     *                       de generarla o desde el cliente: el CMS guarda
     *                       únicamente su hash.
     */
    public static function mqttPassword(string $rawKey, string $salt): string
    {
        return hash('sha256', $rawKey . $salt);
    }

    public function __toString(): string { return $this->hostname; }
}
