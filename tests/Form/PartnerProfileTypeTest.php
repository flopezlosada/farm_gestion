<?php

namespace App\Tests\Form;

use App\Entity\City;
use App\Entity\Partner;
use App\Entity\State;
use App\Form\PartnerProfileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Regresión del form de autoservicio {@see PartnerProfileType}.
 *
 * El bug: un socix no podía guardar su perfil (típicamente su dirección)
 * aunque el admin sí. La causa era que el form validaba restricciones
 * `NotBlank` de TODA la entidad Partner, incluidas dos que el socix no
 * gestiona o que los datos legacy tienen mal:
 *   - `share_payment` (periodicidad de pago), a NULL en casi todos los socixs
 *     y ni siquiera presente en el form → error invisible que tumbaba el save.
 *   - `city`/`state`, con socixs cuya ciudad no pertenece a su provincia
 *     (homónimos mal enganchados: "Torremocha" de Cáceres bajo Madrid) → el
 *     select filtrado por provincia salía vacío y `NotBlank` lo tumbaba.
 *
 * El arreglo acota la validación al grupo `profile` (solo nombre y apellidos)
 * y preserva la ciudad actual como opción del select. Estos tests usan formas
 * de datos REALISTAS (campos a NULL como en producción).
 *
 * Autocontenido: crea sus propios State/City/Partner, no depende del estado
 * de db_test más allá del catálogo base.
 */
class PartnerProfileTypeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->factory = static::getContainer()->get('form.factory');
    }

    /**
     * Caso Oscar en prod: forma de pago a NULL y ciudad de otra provincia.
     * Antes fallaba la validación y no guardaba; ahora valida, guarda la
     * dirección nueva y CONSERVA la ciudad legacy (no la borra).
     */
    public function testSocixConFormaDePagoNulaYCiudadDescuadradaGuardaLaDireccion(): void
    {
        $madrid = $this->madrid();

        // Provincia + ciudad ajenas a Madrid, para el mismatch.
        $caceres = (new State())->setName('Cáceres (test ' . uniqid() . ')');
        $this->em->persist($caceres);
        $torremocha = (new City())->setName('Torremocha (test)');
        $torremocha->setState($caceres);
        $this->em->persist($torremocha);

        $partner = new Partner();
        $partner->setName('Oscar (test)');
        $partner->setSurname('Fernández (test)');
        $partner->setIsActive(true);
        $partner->setAddress('Dirección vieja');
        $partner->setState($madrid);
        $partner->setCity($torremocha); // ciudad de Cáceres bajo provincia Madrid
        // share_payment se queda a NULL a propósito.
        $this->em->persist($partner);
        $this->em->flush();

        $form = $this->createProfileForm($partner);
        $form->submit([
            'name' => 'Oscar (test)',
            'surname' => 'Fernández (test)',
            'DNI' => '',
            'address' => 'Dirección NUEVA 123',
            'state' => (string) $madrid->getId(),
            'city' => (string) $torremocha->getId(), // el select ya la ofrece
        ]);

        $this->assertTrue($form->isSynchronized(), 'El form debe sincronizar.');
        $this->assertTrue($form->isValid(), 'Debe validar pese a share_payment NULL y ciudad de otra provincia. Errores: ' . (string) $form->getErrors(true));

        $this->em->flush();
        $this->em->refresh($partner);
        $this->assertSame('Dirección NUEVA 123', $partner->getAddress());
        $this->assertSame($torremocha->getId(), $partner->getCity()->getId(), 'La ciudad legacy debe conservarse, no borrarse.');
        $this->assertNull($partner->getSharePayment(), 'El form del socix no toca la periodicidad de pago.');
    }

    /**
     * Socix con provincia y municipio a NULL: debe poder guardar su dirección
     * igualmente (la geografía es secundaria; el reparto va por nodos).
     */
    public function testSocixSinProvinciaNiMunicipioGuardaLaDireccion(): void
    {
        $partner = new Partner();
        $partner->setName('Sin Geo (test)');
        $partner->setSurname('De Prueba (test)');
        $partner->setIsActive(true);
        $partner->setAddress('Dirección vieja');
        // state, city y share_payment a NULL.
        $this->em->persist($partner);
        $this->em->flush();

        $form = $this->createProfileForm($partner);
        $form->submit([
            'name' => 'Sin Geo (test)',
            'surname' => 'De Prueba (test)',
            'DNI' => '',
            'address' => 'Dirección NUEVA',
            'state' => '',
            'city' => '',
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid(), 'Sin provincia/municipio debe poder guardar. Errores: ' . (string) $form->getErrors(true));

        $this->em->flush();
        $this->em->refresh($partner);
        $this->assertSame('Dirección NUEVA', $partner->getAddress());
    }

    /**
     * Happy path: un socix cambia legítimamente a una provincia y municipio
     * NUEVOS y válidos (no la que ya tenía). Debe guardarse.
     */
    public function testSocixCambiaProvinciaYMunicipioAUnaCombinacionValidaNueva(): void
    {
        $madrid = $this->madrid();
        $madridCity = $this->em->getRepository(City::class)->findOneBy(['state' => $madrid, 'name' => 'Madrid']);
        $this->assertNotNull($madridCity, 'El catálogo debe tener la ciudad Madrid.');

        // Socix que arranca en otra provincia distinta.
        $otra = (new State())->setName('Otra Provincia (test ' . uniqid() . ')');
        $this->em->persist($otra);
        $ciudadOtra = (new City())->setName('Pueblo Otro (test)');
        $ciudadOtra->setState($otra);
        $this->em->persist($ciudadOtra);

        $partner = new Partner();
        $partner->setName('Muda (test)');
        $partner->setSurname('De Provincia (test)');
        $partner->setIsActive(true);
        $partner->setState($otra);
        $partner->setCity($ciudadOtra);
        $this->em->persist($partner);
        $this->em->flush();

        $form = $this->createProfileForm($partner);
        $form->submit([
            'name' => 'Muda (test)',
            'surname' => 'De Provincia (test)',
            'DNI' => '',
            'address' => 'Nueva dirección en Madrid',
            'state' => (string) $madrid->getId(),
            'city' => (string) $madridCity->getId(), // ciudad válida de la provincia elegida
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid(), 'Un cambio de provincia/municipio válido debe guardar. Errores: ' . (string) $form->getErrors(true));

        $this->em->flush();
        $this->em->refresh($partner);
        $this->assertSame($madrid->getId(), $partner->getState()->getId());
        $this->assertSame($madridCity->getId(), $partner->getCity()->getId());
    }

    /**
     * Seguridad: el socix NO puede fijar una ciudad de otra provincia que no
     * sea la que ya tenía. Enviar state=Madrid + una ciudad ajena (distinta de
     * la suya) debe rechazarse como choice inválida (form no válido).
     */
    public function testSocixNoPuedeInyectarCiudadArbitrariaDeOtraProvincia(): void
    {
        $madrid = $this->madrid();

        // Ciudad de otra provincia que el socix NUNCA ha tenido.
        $ajena = (new State())->setName('Ajena (test ' . uniqid() . ')');
        $this->em->persist($ajena);
        $ciudadAjena = (new City())->setName('Pueblo Ajeno (test)');
        $ciudadAjena->setState($ajena);
        $this->em->persist($ciudadAjena);

        // El socix arranca en Madrid, sin relación con la ciudad ajena.
        $partner = new Partner();
        $partner->setName('Sin Truco (test)');
        $partner->setSurname('De Prueba (test)');
        $partner->setIsActive(true);
        $partner->setState($madrid);
        $this->em->persist($partner);
        $this->em->flush();

        $form = $this->createProfileForm($partner);
        $form->submit([
            'name' => 'Sin Truco (test)',
            'surname' => 'De Prueba (test)',
            'DNI' => '',
            'address' => 'X',
            'state' => (string) $madrid->getId(),
            'city' => (string) $ciudadAjena->getId(), // ciudad ajena que no le pertenece
        ]);

        $this->assertFalse($form->isValid(), 'Una ciudad ajena que el socix nunca tuvo no debe aceptarse.');
    }

    /**
     * La identidad sí se protege: el grupo de validación `profile` mantiene el
     * NotBlank de nombre Y apellidos, así que un refactor que quitara 'profile'
     * de esos campos (reintroduciendo el bug inverso: socix sin nombre) lo
     * cazaría este test. Se valida la ENTIDAD contra el grupo directamente, no
     * vía el form: enviar '' por el form lo normaliza a null y el setter
     * no-nullable de name reventaría con TypeError antes de validar (footgun
     * preexistente, ajeno a este cambio).
     */
    public function testGrupoProfileExigeNombreYApellidos(): void
    {
        $validator = static::getContainer()->get('validator');

        $partner = new Partner();
        $partner->setName('');
        $partner->setSurname('');

        $paths = array_map(
            static fn ($v) => $v->getPropertyPath(),
            iterator_to_array($validator->validate($partner, null, ['profile']))
        );

        $this->assertContains('name', $paths, 'El grupo profile debe exigir el nombre.');
        $this->assertContains('surname', $paths, 'El grupo profile debe exigir los apellidos.');
    }

    private function madrid(): State
    {
        $madrid = $this->em->getRepository(State::class)->findOneBy(['name' => 'Madrid']);
        $this->assertNotNull($madrid, 'El catálogo de db_test debe tener la provincia Madrid (CatalogFixtures).');

        return $madrid;
    }

    private function createProfileForm(Partner $partner)
    {
        // CSRF off: aquí probamos la lógica de validación/mapeo, no el token.
        return $this->factory->create(PartnerProfileType::class, $partner, ['csrf_protection' => false]);
    }
}
