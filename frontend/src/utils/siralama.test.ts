import { describe, expect, it } from 'vitest'

import { trSirala } from './siralama'

describe('trSirala (Türkçe sıralama)', () => {
  it('Türkçe harfleri alfabedeki doğru yere koyar — ASCII gibi en alta atmaz', () => {
    // ASCII sıralamada Ş (U+015E) Z'den (U+005A) SONRA gelir → 'Şükrü' listenin
    // en altına düşerdi; Türkçe'de A < B < Ç < Ş < Z olmalı
    const adlar = ['Zehra', 'Ahmet', 'Şükrü', 'Çağrı', 'Bora']
    expect([...adlar].sort(trSirala)).toEqual(['Ahmet', 'Bora', 'Çağrı', 'Şükrü', 'Zehra'])
  })

  it('büyük/küçük harf farkı sırayı bozmaz (sensitivity: base)', () => {
    // Salt ASCII olsaydı büyük harfler (A-Z) küçüklerden (a-z) önce gruplanır,
    // 'bora' 'Ali'den önce gelirdi; base duyarlılıkta harf değeri baskındır
    expect([...['bora', 'Ali', 'Cem']].sort(trSirala)).toEqual(['Ali', 'bora', 'Cem'])
  })

  it('sayısal parçalar sözlük değil sayı olarak sıralanır (numeric)', () => {
    // Sözlük sıralamasında '10' < '9' < '2' olurdu (ilk karakter '1' < '9')
    expect([...['Kod 10', 'Kod 9', 'Kod 2']].sort(trSirala)).toEqual(['Kod 2', 'Kod 9', 'Kod 10'])
  })

  it('null/undefined boş string sayılır (karşılaştırmaya girenler en başa)', () => {
    // Not: Array.prototype.sort undefined'ı karşılaştırıcıya SOKMADAN sona atar;
    // null ise karşılaştırılır ('' → en başa)
    expect([...['Veli', null, 'Ali']].sort(trSirala)).toEqual([null, 'Ali', 'Veli'])
  })
})
