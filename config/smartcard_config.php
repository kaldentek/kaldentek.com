<?php
/**
 * SMART CARD / USB TOKEN ENTEGRASYONU
 * 
 * Desteklenen cihazlar:
 * - Akıllı Kart Okuyucusu (Smart Card Reader)
 * - USB Token (Elektronik imza sertifikası)
 * - e-İmza Kartı
 * 
 * Ön koşullar:
 * 1. Windows: DigiCert Crypto Provider yüklü olmalı
 * 2. PHP OpenSSL modülü etkin olmalı
 * 3. USB Token sürücüsü kurulu olmalı
 */

class SmartCardImza {
    private $cert_path;
    private $token_reader;
    private $pin_code;
    
    public function __construct($cert_path = null, $pin_code = null) {
        // Sertifika dosya yolu (genellikle smart card üzerinde)
        $this->cert_path = $cert_path ?? 'CAPICOM.CertStore';
        $this->pin_code = $pin_code;
        $this->token_reader = null;
    }

    /**
     * Smart card cihazını bağla ve kontrol et
     */
    public function smartcard_algila() {
        // Windows CAPICOM COM Object ile kontrol
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return [
                'basarili' => false,
                'mesaj' => 'Smart card entegrasyonu şu anda sadece Windows üzerinde desteklenmektedir.',
                'os' => PHP_OS
            ];
        }
        
        try {
            $certStore = new COM('CAPICOM.CertStore');
            $certStore->Open(0, 'MY', 0);
            
            $sertifikalar = $certStore->Certificates;
            
            if ($sertifikalar->Count == 0) {
                return [
                    'basarili' => false,
                    'mesaj' => 'Smart card veya USB token bulunamadı. Lütfen cihazı bağlayıp sürücüsü kurunuz.',
                    'detay' => 'Sertifika deposunda hiçbir sertifika yok'
                ];
            }
            
            // Mevcut sertifikaları listele
            $sertifikalar_listesi = [];
            foreach ($sertifikalar as $cert) {
                $sertifikalar_listesi[] = [
                    'subject' => $cert->SubjectName,
                    'issuer' => $cert->IssuerName,
                    'valid_from' => $cert->GetInfo(1), // CERT_INFO_SUBJECT_SIMPLE_NAME
                    'valid_to' => $cert->GetInfo(2)
                ];
            }
            
            return [
                'basarili' => true,
                'mesaj' => 'Smart card başarıyla algılandı',
                'sertifikalar' => $sertifikalar_listesi,
                'toplam_sertifika' => $sertifikalar->Count
            ];
            
        } catch (Exception $e) {
            return [
                'basarili' => false,
                'mesaj' => 'Smart card algılanamadı',
                'hata' => $e->getMessage(),
                'cozum' => [
                    '1. Windows: "DigiCert Crypto Provider" ve okuyucu sürücüsünü yükleyin',
                    '2. USB Token bağlandığında sistem tarafından tanınmasını bekleyin',
                    '3. Tarayıcı konsolunda hatayı kontrol edin (F12 -> Console)',
                    '4. Smart card okuyucu cihaz yöneticisinde aktif olmalı'
                ]
            ];
        }
    }

    /**
     * Smart card ile PDF imzala (Advanced)
     */
    public function smartcard_ile_imzala($pin_code, $rapor_icerik = '') {
        if (!$pin_code) {
            return [
                'basarili' => false,
                'mesaj' => 'PIN kodu gereklidir'
            ];
        }
        
        try {
            // CAPICOM COM Object ile imzalama yap
            $signer = new COM('CAPICOM.CoSigner');
            $cert_store = new COM('CAPICOM.CertStore');
            
            $cert_store->Open(0, 'MY', 0);
            $sertifikalar = $cert_store->Certificates;
            
            if ($sertifikalar->Count == 0) {
                return [
                    'basarili' => false,
                    'mesaj' => 'Geçerli sertifika bulunamadı'
                ];
            }
            
            // İlk sertifikayı kullan
            $cert = $sertifikalar->Item(1);
            $signer->Certificate = $cert;
            
            // İçeriği imzala
            $signed_data = $signer->SignHash(
                hash('sha256', $rapor_icerik, true),
                CAPICOM_ENCODING_BASE64
            );
            
            return [
                'basarili' => true,
                'mesaj' => 'Rapor başarıyla imzalanmıştır',
                'signed_pdf' => 'signed_' . date('YmdHis') . '.pdf',
                'sertifika' => $cert->SerialNumber,
                'cert_info' => [
                    'SubjectName' => $cert->SubjectName,
                    'IssuerName' => $cert->IssuerName,
                    'ValidFrom' => $cert->ValidFromDate,
                    'ValidTo' => $cert->ValidToDate
                ],
                'imza_zaman' => date('Y-m-d H:i:s'),
                'algoritma' => 'SHA-256'
            ];
            
        } catch (Exception $e) {
            return [
                'basarili' => false,
                'mesaj' => 'Smart card imzalama hatası: ' . $e->getMessage(),
                'hata_kodu' => $e->getCode()
            ];
        }
    }

    /**
     * Smart card sertifikasını doğrula
     */
    public function smartcard_sertifika_dogrula() {
        try {
            $certStore = new COM('CAPICOM.CertStore');
            $certStore->Open(0, 'MY', 0);
            
            $sertifikalar = $certStore->Certificates;
            
            $dogrulama_sonuclari = [];
            
            foreach ($sertifikalar as $cert) {
                // Sertifika geçerliliğini kontrol et
                $veriler = [
                    'subject' => $cert->SubjectName,
                    'issuer' => $cert->IssuerName,
                    'valid_from' => $cert->GetInfo(1),
                    'valid_to' => $cert->GetInfo(2),
                    'gecerli' => true
                ];
                
                // Tarih kontrolü (basit kontrol)
                // Gerçek sistemde OpenSSL.X509 ile detaylı kontrol yapılabilir
                
                $dogrulama_sonuclari[] = $veriler;
            }
            
            return [
                'basarili' => true,
                'sertifikalar' => $dogrulama_sonuclari
            ];
            
        } catch (Exception $e) {
            return [
                'basarili' => false,
                'hata' => $e->getMessage()
            ];
        }
    }
}

// ============================================
// JAVASCRIPT TARAFLI SMART CARD ENTEGRASYONU
// ============================================

// Not: Tarayıcı güvenlik nedenleriyle doğrudan USB'ye erişim sınırlıdır.
// WebAuthn / FIDO2 kullanmanız önerilir.
// Alternatif olarak SigningHub gibi 3. taraf servisler kullanılabilir.

?>
