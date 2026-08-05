import { z } from 'zod'

export const loginSchema = z.object({
  kullanici_adi: z.string().min(1, 'giris.dogrulama.kullaniciAdiZorunlu').max(128),
  sifre: z.string().min(1, 'giris.dogrulama.sifreZorunlu').max(255),
})

export type LoginGirdisi = z.infer<typeof loginSchema>
