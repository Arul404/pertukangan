<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\TukangAdmin\Config\Bsre as BsreConfig;
use Modules\TukangAdmin\Libraries\BsreClient;
use Modules\TukangAdmin\Libraries\BsreClientException;

/**
 * BsreClient diuji terhadap balikan BSrE yang SUNGGUHAN, direkam dari portal.
 *
 * Fixture di tests/_support/Fixtures/Bsre/ adalah potongan HAR asli (NIK, NIP,
 * dan nomor sudah dimasking saat perekaman; satu baris milik pengguna lain
 * diganti nilai contoh). Menguji terhadap balikan asli itulah yang membedakan
 * "pembaca yang toleran" dari "pembaca yang kebetulan lolos": tebakan nama kolom
 * yang meleset — `noHp` alih-alih `phone`, `email` alih-alih `emailAddress` —
 * hanya ketahuan di sini.
 *
 * Yang dijaga paling ketat adalah SATU-SATUNYA panggilan yang menulis data
 * pengguna. Menyimpan profil berarti mengirim ulang kolom-kolomnya; salah kolom
 * berarti kerusakan diam pada data orang.
 *
 * @internal
 */
final class BsreClientRecordedTest extends CIUnitTestCase
{
    private const UID = 'da48751e-361d-4bc8-9938-0f27ce0f1e71';

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $path = SUPPORTPATH . 'Fixtures/Bsre/' . $name . '.json';

        $this->assertFileExists($path, 'Fixture rekaman BSrE hilang: ' . $name);

        return json_decode((string) file_get_contents($path), true);
    }

    /**
     * Klien dengan lapisan HTTP diganti pemutar rekaman: tiap path dipetakan ke
     * balikan yang pernah benar-benar dikirim BSrE.
     *
     * @param array<string, array<string, mixed>> $routes path fragment => balikan JSON
     */
    private function client(array $routes, ?BsreConfig $config = null): object
    {
        return new class ($config ?? new BsreConfig(), $routes) extends BsreClient {
            /** @var list<array{method: string, path: string, body: ?array}> */
            public array $sent = [];

            /** @param array<string, array<string, mixed>> $routes */
            public function __construct(BsreConfig $config, private array $routes)
            {
                parent::__construct($config);
                $this->withToken('token-uji');
            }

            protected function request(string $method, string $path, ?array $body = null, bool $auth = true): array
            {
                $this->sent[] = ['method' => $method, 'path' => $path, 'body' => $body];

                foreach ($this->routes as $fragment => $json) {
                    if (str_contains($path, $fragment)) {
                        return ['code' => 200, 'json' => $json, 'body' => (string) json_encode($json)];
                    }
                }

                return ['code' => 404, 'json' => null, 'body' => ''];
            }
        };
    }

    // -----------------------------------------------------------------
    // Membaca profil: tempat tebakan nama kolom terbukti benar atau tidak
    // -----------------------------------------------------------------

    public function testDetailPenggunaTerbacaDariBalikanAsli(): void
    {
        $client = $this->client(['user/details' => $this->fixture('user_details')]);
        $user   = $client->userDetails(self::UID);

        $this->assertSame('FAHRUR RAJI', $user['name']);
        // Kolomnya bernama emailAddress. Sebelum ini dikenali, pencarian lewat
        // NIK/No HP menghasilkan email KOSONG — dan email itulah yang dipakai
        // mencari antrean persetujuan di langkah berikutnya.
        $this->assertSame('fahrur.raze@magelangkab.go.id', $user['email']);
        $this->assertSame('62*********42', $user['phone']);
        $this->assertSame('************0001', $user['nik']);
        $this->assertTrue($user['phoneVerified']);
    }

    public function testSertifikatTerbitDidahulukan(): void
    {
        $details = $this->fixture('user_details');

        // Sertifikat yang sudah dicabut ditaruh PALING DEPAN oleh server. Kalau
        // urutan balikan dituruti begitu saja, reset akan menyasar sertifikat mati.
        $issued  = $details['data']['sertifikat'][0];
        $revoked = $issued;
        $revoked['serialNumber'] = 'aaaabbbbccccdddd';
        $revoked['status']       = 'REVOKE';
        array_unshift($details['data']['sertifikat'], $revoked);

        $client = $this->client(['user/details' => $details]);
        $user   = $client->userDetails(self::UID);

        $this->assertSame($issued['serialNumber'], $user['certificates'][0]['serial']);
        $this->assertSame('ISSUE', $user['certificates'][0]['status']);
    }

    public function testPencarianNikMengirimFilterDanSearchKosong(): void
    {
        $client = $this->client([
            'user/list'    => $this->fixture('user_list_by_nik'),
            'user/details' => $this->fixture('user_details'),
        ]);

        $client->findUser('3300000000000001', 'nik');

        $body = $client->sent[0]['body'];

        // Portal mengirim search KOSONG dan menaruh nilainya hanya di filters.
        $this->assertSame('', $body['search']);
        $this->assertSame(['nik' => '3300000000000001'], $body['filters']);
    }

    /**
     * Baris hasil pencarian NIK tidak memuat NIK-nya (kolom nik/nip/phone dikirim
     * kosong), jadi pencocokan tekstual mustahil. Yang menyelamatkan hanyalah
     * aturan "kalau cuma satu baris, ambil itu" — dan aturan itu harus tetap
     * begitu: menebak satu dari beberapa baris justru yang berbahaya.
     */
    public function testBarisNikKosongTetapMenghasilkanUidYangBenar(): void
    {
        $rows = $this->fixture('user_list_by_nik');

        $this->assertSame('', $rows['data']['aaData'][0]['nik'], 'Premis tesnya: baris NIK memang kosong.');

        $client = $this->client([
            'user/list'    => $rows,
            'user/details' => $this->fixture('user_details'),
        ]);

        $client->findUser('3300000000000001', 'nik');

        $this->assertStringContainsString(
            $rows['data']['aaData'][0]['id'],
            $client->sent[1]['path'],
            'Detail harus dibuka untuk uid dari baris itu.',
        );
    }

    public function testPencarianAmbiguTidakMenebakSiapaPun(): void
    {
        $rows = $this->fixture('user_list_by_nik');
        $second = $rows['data']['aaData'][0];
        $second['id'] = '99999999-8888-7777-6666-555555555555';
        $rows['data']['aaData'][] = $second;

        $client = $this->client(['user/list' => $rows]);

        $this->expectException(BsreClientException::class);
        $this->expectExceptionMessageMatches('/menghasilkan 2 pengguna/');

        $client->findUser('3300000000000001', 'nik');
    }

    // -----------------------------------------------------------------
    // Menulis profil
    // -----------------------------------------------------------------

    public function testMenyimpanNomorHanyaMengirimKolomYangPortalKirim(): void
    {
        $client = $this->client([
            'user/details' => $this->fixture('user_details'),
            'user/edit'    => $this->fixture('user_edit_response'),
        ]);

        $result = $client->updateUserPhone(self::UID, '081234567890');

        $edit = null;

        foreach ($client->sent as $call) {
            if (str_contains($call['path'], 'user/edit')) {
                $edit = $call;
            }
        }

        $this->assertNotNull($edit, 'Permintaan simpan tidak pernah dikirim.');
        $this->assertSame('POST', $edit['method']);
        $this->assertStringEndsWith(self::UID, $edit['path']);

        // Persis 12 kolom yang portal kirim — tidak kurang, dan yang lebih penting
        // TIDAK LEBIH: status, role, certificateStatus, linkAktif dan kawan-kawan
        // tidak pernah dikirim portal pada permintaan yang menulis data pengguna.
        $this->assertSame([
            'ktpId', 'fotoId', 'videoId', 'nik', 'nip', 'emailAddress', 'nama',
            'phone', 'provinsi', 'jabatanOrganisasi', 'organisasi', 'organisasiUnit',
        ], array_keys($edit['body']));

        // Hanya nomornya yang berubah; sisanya persis nilai dari profil.
        $profile = $this->fixture('user_details')['data']['profile'];

        foreach ($edit['body'] as $key => $value) {
            if ($key !== 'phone') {
                $this->assertSame($profile[$key], $value, 'Kolom "' . $key . '" ikut bergeser.');
            }
        }

        $this->assertSame('081234567890', $edit['body']['phone']);
        $this->assertSame('62*********42', $result['before']);
        $this->assertStringContainsString('link persetujuan', $result['message']);
    }

    public function testProfilTanpaSalahSatuKolomMembatalkanPengiriman(): void
    {
        $details = $this->fixture('user_details');
        unset($details['data']['profile']['jabatanOrganisasi']);

        $client = $this->client(['user/details' => $details, 'user/edit' => []]);

        try {
            $client->updateUserPhone(self::UID, '081234567890');
            $this->fail('Seharusnya membatalkan penyimpanan.');
        } catch (BsreClientException $e) {
            $this->assertStringContainsString('jabatanOrganisasi', $e->getMessage());
        }

        foreach ($client->sent as $call) {
            $this->assertStringNotContainsString('user/edit', $call['path'], 'Tidak boleh ada yang dikirim.');
        }
    }

    public function testEndpointBelumDikonfigurasiMenyebutKunciEnvNya(): void
    {
        $config                 = new BsreConfig();
        $config->userUpdatePath = '';

        $client = $this->client(['user/details' => $this->fixture('user_details')], $config);

        $this->expectException(BsreClientException::class);
        $this->expectExceptionMessageMatches('/bsre\.userUpdatePath/');

        $client->updateUserPhone(self::UID, '081234567890');
    }

    // -----------------------------------------------------------------
    // Persetujuan perubahan
    // -----------------------------------------------------------------

    public function testPersetujuanMemakaiUidDanApproveBerupaString(): void
    {
        $client = $this->client([
            'verify/user/update'   => $this->fixture('verify_update_list'),
            'verify/user/approval' => $this->fixture('approval_response'),
        ]);

        $request = $client->findUpdateRequest(self::UID, 'fahrur.raze@magelangkab.go.id', 'email');
        $message = $client->approveUserUpdate($request['id']);

        $this->assertSame(self::UID, $request['id'], 'Baris antrean ber-id sama dengan uid pengguna.');
        $this->assertSame('UPDATE', $request['raw']['status']);

        $approval = $client->sent[1];

        $this->assertSame([
            'id'      => self::UID,
            'approve' => 'true',   // string, bukan boolean — begitulah portal mengirimnya
            'message' => 'Data terverifikasi',
        ], $approval['body']);

        $this->assertSame('Approval User Berhasil', $message);
    }

    /**
     * Antrean dicari lewat email/NIK, jadi baris yang terbawa bisa saja milik
     * orang lain dengan nilai yang mirip. Menyetujui perubahan orang keliru adalah
     * hal terburuk yang bisa dilakukan langkah ini.
     */
    public function testPersetujuanMenolakBarisMilikPenggunaLain(): void
    {
        $client = $this->client(['verify/user/update' => $this->fixture('verify_update_list')]);

        $this->expectException(BsreClientException::class);
        $this->expectExceptionMessageMatches('/menunggu persetujuan/');

        $client->findUpdateRequest('uid-orang-lain', 'fahrur.raze@magelangkab.go.id', 'email');
    }

    public function testAntreanKosongDilaporkanSebagaiBelumTersimpan(): void
    {
        $empty = $this->fixture('verify_update_list');
        $empty['data']['aaData'] = [];

        $client = $this->client(['verify/user/update' => $empty]);

        $this->expectException(BsreClientException::class);
        $this->expectExceptionMessageMatches('/sudah disetujui, atau belum tersimpan/');

        $client->findUpdateRequest(self::UID, 'fahrur.raze@magelangkab.go.id', 'email');
    }
}
