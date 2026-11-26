import { describe, expect, it } from 'vitest'

import { panoyuCoz } from './yapistirma'

describe('panoyuCoz (Excel panosu)', () => {
  it('satırları ve hücreleri ayırır; satır sonu biçimi ve sondaki satır sonu önemsiz', () => {
    expect(panoyuCoz('A\t B \tC\r\nD\t\tF\r\n')).toEqual([
      ['A', 'B', 'C'],
      ['D', '', 'F'],
    ])
  })

  it('Alt+Enter ile bölünmüş hücre ayrı satır olmaz, satır sonları boşluğa döner', () => {
    // Excel böyle hücreyi tırnak içinde verir; boş satırlar da (Alt+Enter iki kez) içindedir
    const pano =
      'MAKİNA-EKİPMAN\tHAB\t"BETON POMPASI İŞİ BİTMEDİĞİNDEN DOLAYI\n\nBEKLEME 2 SAAT MESAİ\n\n(MUSTAFA KARA)"\tKAZI-DOLGU\t3.472,28\r\n' +
      'MAKİNA-EKİPMAN\tHAB\tHAFRİYAT YÜKLEME\tHAFRİYAT\t8.680,71\r\n'

    expect(panoyuCoz(pano)).toEqual([
      [
        'MAKİNA-EKİPMAN',
        'HAB',
        'BETON POMPASI İŞİ BİTMEDİĞİNDEN DOLAYI BEKLEME 2 SAAT MESAİ (MUSTAFA KARA)',
        'KAZI-DOLGU',
        '3.472,28',
      ],
      ['MAKİNA-EKİPMAN', 'HAB', 'HAFRİYAT YÜKLEME', 'HAFRİYAT', '8.680,71'],
    ])
  })

  it('tırnaklı hücredeki ikilenmiş tırnak tek tırnağa döner', () => {
    expect(panoyuCoz('"12"" BORU\nHATTI"\tX')).toEqual([['12" BORU HATTI', 'X']])
  })

  it('tek satırlık hücredeki tırnaklar içeriğin parçasıdır', () => {
    // Excel tek satırlık hücreyi tırnaklamaz: kapanan tırnağın ardından hücre sürüyorsa tırnak veridir
    expect(panoyuCoz('"A" TİPİ VİNÇ\t12" BORU\r\n"B"\tC')).toEqual([
      ['"A" TİPİ VİNÇ', '12" BORU'],
      ['B', 'C'],
    ])
  })
})
