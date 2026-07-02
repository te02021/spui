<?php

declare(strict_types=1);

namespace SPUI\Controller\Cms;

use Doctrine\Persistence\ManagerRegistry;
use SPUI\Entity\Contenido;
use SPUI\Enum\EstadoContenido;
use SPUI\Form\ContenidoType;
use SPUI\Repository\ContenidoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/spui/contenidos')]
class ContenidoCmsController extends AbstractController
{
    private const TIPOS_ARCHIVO = ['imagen', 'video'];

    public function __construct(
        private readonly ContenidoRepository $repo,
        private readonly ManagerRegistry $doctrine,
        private readonly SluggerInterface $slugger,
        #[Autowire('%kernel.project_dir%/public/uploads/spui')]
        private readonly string $uploadDir,
    ) {}

    private function em()
    {
        return $this->doctrine->getManager('SPUI');
    }

    #[Route('', name: 'spui_cms_contenidos_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SPUI/contenidos/index.html.twig', [
            'contenidos' => $this->repo->findBy([], ['titulo' => 'ASC']),
        ]);
    }

    #[Route('/nuevo', name: 'spui_cms_contenidos_new', methods: ['GET', 'POST'])]
    public function nuevo(Request $request): Response
    {
        $contenido = new Contenido();
        $form      = $this->createForm(ContenidoType::class, $contenido, [
            'action' => $this->generateUrl('spui_cms_contenidos_new'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tipo = $contenido->getTipo();
            $contenido->setEstado(EstadoContenido::Borrador);
            $contenido->setCreadoPorId((int) $this->getUser()->getId());

            if (in_array($tipo->value, self::TIPOS_ARCHIVO, true)) {
                $archivo = $request->files->get('archivo');
                if (!$archivo || !$archivo->isValid()) {
                    $errMsg = 'El archivo es requerido para el tipo "' . $tipo->value . '".';
                    if ($request->isXmlHttpRequest()) {
                        return $this->json([
                            'success' => false,
                            'html'    => $this->renderView('@SPUI/contenidos/_form.html.twig', ['form' => $form]),
                        ]);
                    }
                    $this->addFlash('error', $errMsg);
                    return $this->render('@SPUI/contenidos/new.html.twig', ['form' => $form]);
                }
                $ext      = $archivo->guessExtension() ?? $archivo->getClientOriginalExtension();
                $slug     = $this->slugger->slug(pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME));
                $filename = $slug . '-' . uniqid() . '.' . $ext;
                $archivo->move($this->uploadDir, $filename);
                $contenido->setRutaArchivo('uploads/spui/' . $filename);
                $contenido->setHashArchivo(hash_file('sha256', $this->uploadDir . '/' . $filename));
            } else {
                $texto = trim($request->request->get('contenido_texto', ''));
                if (!$texto) {
                    if ($request->isXmlHttpRequest()) {
                        return $this->json([
                            'success' => false,
                            'html'    => $this->renderView('@SPUI/contenidos/_form.html.twig', ['form' => $form]),
                        ]);
                    }
                    $this->addFlash('error', 'El campo de texto/URL es requerido para este tipo.');
                    return $this->render('@SPUI/contenidos/new.html.twig', ['form' => $form]);
                }
                $contenido->setContenidoTexto($texto);
            }

            $this->em()->persist($contenido);
            $this->em()->flush();
            $msg = 'Contenido "' . $contenido->getTitulo() . '" creado correctamente.';
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'message' => $msg]);
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'title' => 'Nuevo contenido',
                'html'  => $this->renderView('@SPUI/contenidos/_form.html.twig', ['form' => $form]),
            ]);
        }

        return $this->render('@SPUI/contenidos/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/publicar', name: 'spui_cms_contenidos_publicar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function publicar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) {
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => 'Contenido no encontrado.'], 404); }
            $this->addFlash('error', 'Contenido no encontrado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        $c->setEstado(EstadoContenido::Publicado);
        $this->em()->flush();
        $msg = '"' . $c->getTitulo() . '" publicado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    #[Route('/{id}/archivar', name: 'spui_cms_contenidos_archivar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archivar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) {
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => 'Contenido no encontrado.'], 404); }
            $this->addFlash('error', 'Contenido no encontrado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        $c->setEstado(EstadoContenido::Archivado);
        $this->em()->flush();
        $msg = '"' . $c->getTitulo() . '" archivado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg, 'type' => 'warning']);
        }
        $this->addFlash('warning', $msg);
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }

    #[Route('/{id}/eliminar', name: 'spui_cms_contenidos_eliminar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function eliminar(int $id, Request $request): Response
    {
        $c = $this->repo->find($id);
        if (!$c) {
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => 'Contenido no encontrado.'], 404); }
            $this->addFlash('error', 'Contenido no encontrado.');
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        if (!$c->getPlaylistItems()->isEmpty()) {
            $msg = '"' . $c->getTitulo() . '" está en uso en ' . $c->getPlaylistItems()->count() . ' playlist(s). Retíralo primero.';
            if ($request->isXmlHttpRequest()) { return $this->json(['success' => false, 'message' => $msg], 422); }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('spui_cms_contenidos_index');
        }

        $titulo = $c->getTitulo();
        $this->em()->remove($c);
        $this->em()->flush();
        $msg = '"' . $titulo . '" eliminado.';
        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'message' => $msg]);
        }
        $this->addFlash('success', $msg);
        return $this->redirectToRoute('spui_cms_contenidos_index');
    }
}
