<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const form = useForm({
    partner_type: 'foundation',
    organization_name: '',
    name: '',
    email: '',
    phone: '',
    telegram_chat_id: '',
    address: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase title="Daftar akun EcoLoop" description="Daftarkan Yayasan penerima donasi, UMKM penerima diskon, atau supermarket/toko pemberi donasi dan diskon. Akun menunggu persetujuan Admin EcoLoop.">
        <Head title="Daftar Mitra EcoLoop" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="partner_type">Jenis mitra</Label>
                    <select id="partner_type" v-model="form.partner_type" class="h-10 rounded-md border border-input bg-background px-3 text-sm">
                        <option value="foundation">Yayasan / Badan Amal — menerima produk donasi</option>
                        <option value="umkm">Mitra UMKM — menerima informasi produk diskon</option>
                        <option value="business_owner">Supermarket / Toko — Pemberi Donasi & Diskon</option>
                    </select>
                    <InputError :message="form.errors.partner_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="organization_name">Nama organisasi / usaha</Label
                    ><Input id="organization_name" required v-model="form.organization_name" :placeholder="form.partner_type === 'umkm' ? 'Contoh: Dapur Sejahtera' : form.partner_type === 'business_owner' ? 'Contoh: Supermarket Sejahtera' : 'Contoh: Yayasan Pangan Sejahtera'" /><InputError
                        :message="form.errors.organization_name"
                    />
                </div>
                <p class="sm:col-span-2 -mt-3 text-xs text-muted-foreground">Yayasan menerima donasi, UMKM menerima informasi diskon, dan supermarket/toko mengelola dashboard operasional stok, batch, diskon, dan donasi setelah disetujui Admin EcoLoop. Pemilik dapat memberikan akses operasional kepada staf melalui akun manager/kasir.</p>
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" type="text" required autofocus tabindex="1" autocomplete="name" v-model="form.name" placeholder="Full name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="telegram_chat_id">Telegram Chat ID</Label
                    ><Input id="telegram_chat_id" required v-model="form.telegram_chat_id" placeholder="Chat ID dari bot EcoLoop" /><InputError
                        :message="form.errors.telegram_chat_id"
                    />
                    <p class="text-xs text-muted-foreground">Untuk mendapatkan Chat ID, buka Telegram dan tekan tombol Start pada bot EcoLoopPartner_Bot terlebih dahulu. Chat ID dipakai untuk notifikasi sesuai jenis mitra.</p>
                </div>

                <div class="grid gap-2">
                    <Label for="address">Alamat organisasi</Label
                    ><textarea
                        id="address"
                        required
                        v-model="form.address"
                        rows="3"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        placeholder="Alamat untuk pengambilan donasi"
                    /><InputError :message="form.errors.address" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input id="email" type="email" required tabindex="2" autocomplete="email" v-model="form.email" placeholder="email@example.com" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Phone number</Label>
                    <Input id="phone" type="tel" required autocomplete="tel" v-model="form.phone" placeholder="+62..." />
                    <InputError :message="form.errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        tabindex="3"
                        autocomplete="new-password"
                        v-model="form.password"
                        placeholder="Password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        tabindex="4"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        placeholder="Confirm password"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" tabindex="5" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Create account
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink :href="route('login')" class="underline underline-offset-4" tabindex="6">Log in</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
