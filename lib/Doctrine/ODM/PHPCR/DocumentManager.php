<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ODM\PHPCR;

use Doctrine\ODM\PHPCR\Mapping\ClassMetadataFactory;
use Doctrine\ODM\PHPCR\HTTP\Client;
use Doctrine\Common\EventManager;

/**
 * Document Manager 
 * @author      Jordi Boggiano <j.boggiano@seld.be>
 * @author      Pascal Helfenstein <nicam@nicam.ch>
 */
class DocumentManager
{
    /**
     * @var Configuration
     */
    private $config;

    /**
     * @var Mapping\ClassMetadataFactory
     */
    private $metadataFactory;

    /**
     * @var UnitOfWork
     */
    private $unitOfWork = null;

    /**
     * @var ProxyFactory
     */
    private $proxyFactory = null;

    /**
     * @var array
     */
    private $repositories = array();

    /**
     * @var PHPCRClient
     */
    private $couchDBClient = null;

    /**
     * @var EventManager
     */
    private $evm;

    public function __construct(Configuration $config = null, EventManager $evm = null)
    {
        $this->config = $config ?: new Configuration();
        $this->evm = $evm ?: new EventManager();
        $this->metadataFactory = new ClassMetadataFactory($this);
        $this->unitOfWork = new UnitOfWork($this);
        $this->proxyFactory = new Proxy\ProxyFactory($this, $this->config->getProxyDir(), $this->config->getProxyNamespace(), true);
    }

    /**
     * @return EventManager
     */
    public function getEventManager()
    {
        return $this->evm;
    }

    /**
     * @return \PHPCR\SessionInterface
     */
    public function getPhpcrSession()
    {
        return $this->config->getPhpcrSession();
    }

    /**
     * Factory method for a Document Manager.
     *
     * @param Configuration $config
     * @param EventManager $evm
     * @return DocumentManager
     */
    public static function create(Configuration $config = null, EventManager $evm = null)
    {
        return new DocumentManager($config, $evm);
    }

    /**
     * @return ClassMetadataFactory
     */
    public function getMetadataFactory()
    {
        return $this->metadataFactory;
    }

    /**
     * @return Configuration
     */
    public function getConfiguration()
    {
        return $this->config;
    }

    /**
     * @return ClassMetadataFactory
     */
    public function getClassMetadataFactory()
    {
        return $this->metadataFactory;
    }

    /**
     * @param  string $class
     * @return ClassMetadata
     */
    public function getClassMetadata($class)
    {
        return $this->metadataFactory->getMetadataFor($class);
    }

    /**
     * Find the Document with the given id.
     *
     * Will return null if the document wasn't found.
     *
     * @param string $documentName
     * @param string $id
     * @return object
     */
    public function find($documentName, $id)
    {
        return $this->getRepository($documentName)->find($id);
    }

    /**
     * @param  string $documentName
     * @return Doctrine\ODM\PHPCR\DocumentRepository
     */
    public function getRepository($documentName)
    {
        $documentName  = ltrim($documentName, '\\');
        if (!isset($this->repositories[$documentName])) {
            $class = $this->getClassMetadata($documentName);
            if ($class->customRepositoryClassName) {
                $repositoryClass = $class->customRepositoryClassName;
            } else {
                $repositoryClass = 'Doctrine\ODM\PHPCR\DocumentRepository';
            }
            $this->repositories[$documentName] = new $repositoryClass($this, $class);
        }
        return $this->repositories[$documentName];
    }

    /**
     * Create a Query for the view in the specified design document.
     *
     * @param  string $designDocName
     * @param  string $viewName
     * @return Doctrine\ODM\PHPCR\View\Query
     */
    public function createQuery($designDocName, $viewName)
    {
        $designDoc = $this->config->getDesignDocumentClass($designDocName);
        if ($designDoc) {
            $designDoc = new $designDoc;
        }
        $query = new View\Query($this->config->getHttpClient(), $this->config->getDatabase(), $designDocName, $viewName, $designDoc);
        $query->setDocumentManager($this);
        return $query;
    }

    /**
     * Create a Native query for the view of the specified design document.
     *
     * A native query will return an array of data from the &include_docs=true parameter.
     *
     * @param  string $designDocName
     * @param  string $viewName
     * @return View\NativeQuery
     */
    public function createNativeQuery($designDocName, $viewName)
    {
        $designDoc = $this->config->getDesignDocumentClass($designDocName);
        if ($designDoc) {
            $designDoc = new $designDoc;
        }
        return new View\NativeQuery($this->config->getHttpClient(), $this->config->getDatabase(), $designDocName, $viewName, $designDoc);
    }

    public function persist($object, $path)
    {
        $this->unitOfWork->scheduleInsert($object, $path);
    }

    public function remove($object)
    {
        $this->unitOfWork->scheduleRemove($object);
    }

    /**
     * Refresh the given document by querying the PHPCR to get the current state.
     *
     * @param object $document
     */
    public function refresh($document)
    {
        $this->getRepository(get_class($document))->refresh($document);
    }

    /**
     * Gets a reference to the entity identified by the given type and path
     * without actually loading it, if the entity is not yet loaded.
     *
     * @param string $documentName The name of the entity type.
     * @param mixed $path The entity path.
     * @return object The entity reference.
     */
    public function getReference($documentName, $path)
    {
        $class = $this->metadataFactory->getMetadataFor(ltrim($documentName, '\\'));

        // Check identity map first, if its already in there just return it.
        if ($document = $this->unitOfWork->tryGetByPath($path)) {
            return $document;
        }
        $document = $this->proxyFactory->getProxy($class->name, $path);
        $this->unitOfWork->registerManaged($document, $path, null);

        return $document;
    }

    public function flush()
    {
        $this->unitOfWork->flush();
    }

    /**
     * @param  object $document
     * @return bool
     */
    public function contains($document)
    {
        return $this->unitOfWork->contains($document);
    }

    /**
     * @return UnitOfWork
     */
    public function getUnitOfWork()
    {
        return $this->unitOfWork;
    }

    public function clear()
    {
        // Todo: Do a real delegated clear?
        $this->unitOfWork = new UnitOfWork($this);
    }
}